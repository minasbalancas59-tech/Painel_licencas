unit uAtivacao;

{ =====================================================================
  Modulo de licenciamento web para o Total Scale
  ---------------------------------------------------------------------
  Substitui / convive com o Rockey2 durante a transicao.

  Fluxo de verificacao na inicializacao (ver VerificarLicenca):
    1) Tenta validar a licenca web local (arquivo licenca.dat) - OFFLINE,
       so com a chave publica. Nao precisa de internet.
    2) Se nao houver licenca web valida, cai no Rockey2 (fallback).
    3) Se nenhum dos dois, o app deve abrir a tela de ativacao.

  Seguranca:
    - A licenca e um JSON assinado (Ed25519) emitido pelo seu servidor.
    - O Delphi so tem a CHAVE PUBLICA embutida -> nao consegue FORJAR
      licenca, so verificar. Mesmo com o fonte, ninguem gera licenca
      valida sem a chave privada (que fica so na sua VPS).
    - Anti-retrocesso de relogio: guarda a maior data ja vista.

  DEPENDENCIAS:
    - Verificacao Ed25519: este modulo chama Ed25519_Verify, que voce
      resolve de UMA destas formas (veja uEd25519.pas):
        (a) libsodium.dll ao lado do exe  (recomendado, simples), ou
        (b) implementacao Pascal pura.
  ===================================================================== }

interface

uses
  System.SysUtils, System.Classes, System.JSON, System.NetEncoding,
  System.DateUtils, System.IOUtils, Winapi.Windows, System.Win.Registry;

type
  TResultadoLicenca = record
    Valida:        Boolean;
    Cliente:       string;
    Expira:        TDate;
    Modulos:       string;   // CSV: TBE,RFID,LPR
    Mensagem:      string;   // motivo quando invalida
    DiasRestantes: Integer;
    // --- NOVOS (v2 multi-produto) ---
    Produto:       string;   // ex: "ts6"
    Tier:          string;   // ex: "automacao"
    Nivel:         Integer;  // nivel cumulativo (0 = licenca v1 antiga)
    Carencia:      Integer;  // dias de carencia apos expira
    Versao:        Integer;  // 1 = antiga, 2 = multi-produto
    EmCarencia:    Boolean;  // True se expirou mas ainda dentro da carencia
  end;

  TLicenciamento = class
  private
    FPastaApp: string;
    function ArquivoLicenca: string;
    function LerRelogioMaisAltoVisto: TDateTime;
    procedure GravarRelogioMaisAltoVisto(const AData: TDateTime);
    function RelogioFoiManipulado: Boolean;
  public
    constructor Create;

    { Gera o "codigo da maquina" (fingerprint) a partir de HD + placa-mae.
      E o que o cliente informa para ativacao offline, e o que o Delphi
      manda para a API na ativacao online. }
    function ObterFingerprint: string;

    { dados da maquina para exibir no painel (nome do PC, usuario, SO) }
    function ObterNomeMaquina: string;
    function ObterUsuarioWindows: string;
    function ObterVersaoWindows: string;

    { Valida uma licenca assinada (base64 "payload.assinatura").
      Confere: assinatura, fingerprint == esta maquina, e validade. }
    function ValidarLicencaAssinada(const ALicenca: string): TResultadoLicenca;

    { Grava a licenca recebida (online ou offline) em disco. }
    procedure SalvarLicenca(const ALicenca: string);
    function CarregarLicenca: string;

    { Verificacao completa na inicializacao (item 1 do fluxo). }
    function VerificarLicenca: TResultadoLicenca;

    { Modulo habilitado? ex.: TemModulo('RFID') }
    function TemModulo(const ANome: string): Boolean;

    { Recurso liberado? Compara o nivel da licenca com o minimo exigido.
      Cumulativo: nivel 5 (extreme) libera tudo de 1 a 5.
      Licenca v1 antiga tem Nivel=0 -> tratada como "tudo liberado". }
    function NivelLiberado(ANivelMinimo: Integer): Boolean;

    { Situacao da licenca para o aviso discreto no canto.
      Preenche ADias (dias restantes) e ATexto (mensagem pronta).
      Retorna SIT_OK / SIT_PROXIMO / SIT_CARENCIA / SIT_BLOQUEADA. }
    function SituacaoLicenca(out ADias: Integer; out ATexto: string): Integer;
  end;

{ Chave publica Ed25519 - SUBSTITUA pelo conteudo gerado por
  setup/gerar_chaves.php (arquivo chave_publica.pas). }
const
  CHAVE_PUBLICA_HEX =
    'E4CD69EBC5B934A9177CE95B1A30E0DF604C4878BA354F08CCCF4C056978883A';

  { retornos de SituacaoLicenca }
  SIT_OK        = 0;   // tudo certo, longe de vencer
  SIT_PROXIMO   = 1;   // vence em breve (<= SIT_AVISO_DIAS)
  SIT_CARENCIA  = 2;   // ja venceu, mas ainda roda (dentro da carencia)
  SIT_BLOQUEADA = 3;   // venceu e passou a carencia -> nao roda

  SIT_AVISO_DIAS = 15; // a partir de quantos dias antes mostrar o aviso

var
  Licenciamento: TLicenciamento;

implementation

uses
  uEd25519;   // fornece Ed25519_Verify (ver arquivo companion)

{ ---- utilitarios de baixo nivel de hardware ------------------------ }

function ObterSerialVolumeC: string;
var
  Serial, MaxLen, Flags: DWORD;
  Nome: array[0..MAX_PATH] of Char;
  FS:   array[0..MAX_PATH] of Char;
begin
  Result := '';
  if GetVolumeInformation('C:\', Nome, MAX_PATH, @Serial, MaxLen, Flags, FS, MAX_PATH) then
    Result := IntToHex(Serial, 8);
end;

function ObterUUIDPlaca: string;
var
  Reg: TRegistry;
begin
  { UUID da BIOS/placa via WMI seria o ideal, mas para evitar dependencia
    de COM aqui usamos o MachineGuid do Windows (estavel por instalacao).
    Se quiser algo mais colado ao hardware fisico, troque por consulta WMI
    a Win32_BaseBoard.SerialNumber. }
  Result := '';
  Reg := TRegistry.Create(KEY_READ or KEY_WOW64_64KEY);
  try
    Reg.RootKey := HKEY_LOCAL_MACHINE;
    if Reg.OpenKeyReadOnly('SOFTWARE\Microsoft\Cryptography') then
      Result := Reg.ReadString('MachineGuid');
  finally
    Reg.Free;
  end;
end;

{ hash simples e estavel (FNV-1a) para condensar o fingerprint }
function HashFNV(const S: string): string;
var
  h: UInt64;
  i: Integer;
  b: TBytes;
begin
  b := TEncoding.UTF8.GetBytes(S);
  h := UInt64(14695981039346656037);
  for i := 0 to High(b) do
  begin
    h := h xor b[i];
    h := h * UInt64(1099511628211);
  end;
  Result := IntToHex(h, 16);
end;

{ parse de data no formato ISO simples YYYY-MM-DD, independente do
  formato regional do Windows (nao usa StrToDate). }
function DataISOParaDate(const S: string): TDate;
var
  a, m, d: Word;
  dt: TDateTime;
begin
  Result := 0;
  if Length(S) < 10 then Exit;
  a := StrToIntDef(Copy(S,1,4), 0);
  m := StrToIntDef(Copy(S,6,2), 0);
  d := StrToIntDef(Copy(S,9,2), 0);
  if (a=0) or (m=0) or (d=0) then Exit;
  if TryEncodeDate(a, m, d, dt) then
    Result := dt
  else
    Result := 0;
end;

{ ==================================================================== }

constructor TLicenciamento.Create;
begin
  inherited;
  FPastaApp := ExtractFilePath(ParamStr(0));
end;

function TLicenciamento.ArquivoLicenca: string;
begin
  Result := FPastaApp + 'licenca.dat';
end;

function TLicenciamento.ObterFingerprint: string;
var
  bruto: string;
begin
  { combina serial do HD + guid da maquina, condensa e formata em blocos
    legiveis: XXXX-XXXX-XXXX-XXXX }
  bruto := ObterSerialVolumeC + '|' + ObterUUIDPlaca;
  bruto := HashFNV(bruto);   // 16 chars hex
  Result := Copy(bruto,1,4)+'-'+Copy(bruto,5,4)+'-'+
            Copy(bruto,9,4)+'-'+Copy(bruto,13,4);
end;

function TLicenciamento.ObterNomeMaquina: string;
var
  buf: array[0..MAX_COMPUTERNAME_LENGTH] of Char;
  tam: DWORD;
begin
  tam := Length(buf);
  if GetComputerName(buf, tam) then
    Result := string(buf)
  else
    Result := '';
end;

function TLicenciamento.ObterUsuarioWindows: string;
var
  buf: array[0..256] of Char;
  tam: DWORD;
begin
  tam := Length(buf);
  if GetUserName(buf, tam) then
    Result := string(buf)
  else
    Result := '';
end;

function TLicenciamento.ObterVersaoWindows: string;
var
  vi: TOSVersionInfo;
begin
  Result := 'Windows';
  FillChar(vi, SizeOf(vi), 0);
  vi.dwOSVersionInfoSize := SizeOf(vi);
  {$WARN SYMBOL_DEPRECATED OFF}
  if GetVersionEx(vi) then
    Result := Format('Windows %d.%d (build %d)',
      [vi.dwMajorVersion, vi.dwMinorVersion, vi.dwBuildNumber]);
  {$WARN SYMBOL_DEPRECATED ON}
end;

function TLicenciamento.ValidarLicencaAssinada(const ALicenca: string): TResultadoLicenca;
var
  partes: TArray<string>;
  jsonBytes, sigBytes: TBytes;
  jsonTxt: string;
  jo: TJSONObject;
  expira: TDate;
  fpArquivo: string;
begin
  Result := Default(TResultadoLicenca);
  Result.Valida := False;

  if Trim(ALicenca) = '' then
  begin
    Result.Mensagem := 'Nenhuma licenca instalada.';
    Exit;
  end;

  partes := ALicenca.Split(['.']);
  if Length(partes) <> 2 then
  begin
    Result.Mensagem := 'Formato de licenca invalido.';
    Exit;
  end;

  { base64 url-safe -> bytes }
  try
    jsonBytes := TNetEncoding.Base64URL.DecodeStringToBytes(partes[0]);
    sigBytes  := TNetEncoding.Base64URL.DecodeStringToBytes(partes[1]);
  except
    Result.Mensagem := 'Licenca corrompida.';
    Exit;
  end;

  { 1) verifica a ASSINATURA com a chave publica }
  if not Ed25519_Verify(jsonBytes, sigBytes, HexToBytes(CHAVE_PUBLICA_HEX)) then
  begin
    Result.Mensagem := 'Assinatura invalida (licenca adulterada).';
    Exit;
  end;

  { 2) interpreta o payload }
  jsonTxt := TEncoding.UTF8.GetString(jsonBytes);
  jo := TJSONObject.ParseJSONValue(jsonTxt) as TJSONObject;
  if jo = nil then
  begin
    Result.Mensagem := 'Conteudo da licenca ilegivel.';
    Exit;
  end;
  try
    { 3) fingerprint tem que bater com ESTA maquina }
    fpArquivo := jo.GetValue<string>('fingerprint','');
    if not SameText(fpArquivo, ObterFingerprint) then
    begin
      Result.Mensagem := 'Esta licenca pertence a outra maquina.';
      Exit;
    end;

    { 4) validade }
    expira := DataISOParaDate(jo.GetValue<string>('expira','1900-01-01'));
    Result.Cliente := jo.GetValue<string>('cliente','');
    Result.Modulos := jo.GetValue<string>('modulos','TBE');
    Result.Expira  := expira;

    { campos v2 (defaults seguros para licenca v1 antiga) }
    Result.Versao     := jo.GetValue<Integer>('versao', 1);
    Result.Produto    := jo.GetValue<string>('produto', '');
    Result.Tier       := jo.GetValue<string>('tier', '');
    Result.Nivel      := jo.GetValue<Integer>('nivel', 0);
    Result.Carencia   := jo.GetValue<Integer>('carencia', 0);
    Result.EmCarencia := False;

    if expira >= Date then
      Result.DiasRestantes := DaysBetween(Date, expira)
    else
      Result.DiasRestantes := -DaysBetween(Date, expira);

    if RelogioFoiManipulado then
    begin
      Result.Mensagem := 'Relogio do sistema alterado. Ajuste a data/hora correta.';
      Exit;
    end;

    { expiracao COM periodo de carencia:
        - ainda nao venceu               -> ok
        - venceu, dentro da carencia     -> roda, marca EmCarencia
        - venceu e passou a carencia     -> bloqueia }
    if expira < Date then
    begin
      if (Result.Carencia > 0) and (Date <= (expira + Result.Carencia)) then
        Result.EmCarencia := True   // continua valido durante a carencia
      else
      begin
        Result.Mensagem := 'Licenca expirada em ' + DateToStr(expira) + '.';
        Exit;  // bloqueia
      end;
    end;

    { atualiza o "maior relogio visto" para o anti-retrocesso }
    GravarRelogioMaisAltoVisto(Now);

    Result.Valida := True;
    if Result.EmCarencia then
      Result.Mensagem := 'Licenca em periodo de carencia.'
    else
      Result.Mensagem := 'Licenca valida.';
  finally
    jo.Free;
  end;
end;

procedure TLicenciamento.SalvarLicenca(const ALicenca: string);
begin
  TFile.WriteAllText(ArquivoLicenca, ALicenca, TEncoding.ASCII);
end;

function TLicenciamento.CarregarLicenca: string;
begin
  if TFile.Exists(ArquivoLicenca) then
    Result := Trim(TFile.ReadAllText(ArquivoLicenca, TEncoding.ASCII))
  else
    Result := '';
end;

function TLicenciamento.VerificarLicenca: TResultadoLicenca;
begin
  Result := ValidarLicencaAssinada(CarregarLicenca);
end;

function TLicenciamento.TemModulo(const ANome: string): Boolean;
var
  r: TResultadoLicenca;
begin
  r := VerificarLicenca;
  Result := r.Valida and
            (Pos(UpperCase(ANome), UpperCase(r.Modulos)) > 0);
end;

function TLicenciamento.NivelLiberado(ANivelMinimo: Integer): Boolean;
var
  r: TResultadoLicenca;
begin
  r := VerificarLicenca;
  if not r.Valida then
    Exit(False);

  { Licenca v1 antiga (Nivel=0, sem tiers) nao tem conceito de nivel.
    Para nao travar clientes antigos, tratamos como "tudo liberado".
    Se preferir o oposto (v1 = so o basico), troque para: Exit(False). }
  if r.Nivel <= 0 then
    Exit(True);

  Result := r.Nivel >= ANivelMinimo;
end;

function TLicenciamento.SituacaoLicenca(out ADias: Integer; out ATexto: string): Integer;
var
  r: TResultadoLicenca;
begin
  r := VerificarLicenca;
  ADias := r.DiasRestantes;

  if not r.Valida then
  begin
    ATexto := 'Licenca bloqueada';
    ADias  := 0;
    Exit(SIT_BLOQUEADA);
  end;

  if r.EmCarencia then
  begin
    ATexto := Format('Licenca expirada - carencia: %d dia(s)',
                     [r.Carencia - DaysBetween(Date, r.Expira)]);
    Exit(SIT_CARENCIA);
  end;

  if r.DiasRestantes <= SIT_AVISO_DIAS then
  begin
    ATexto := Format('Sua licenca expira em %d dia(s)', [r.DiasRestantes]);
    Exit(SIT_PROXIMO);
  end;

  ATexto := '';
  Result := SIT_OK;
end;

{ ---- anti-retrocesso de relogio ------------------------------------ }

function TLicenciamento.LerRelogioMaisAltoVisto: TDateTime;
var
  Reg: TRegistry;
  s: string;
begin
  Result := 0;
  Reg := TRegistry.Create(KEY_READ or KEY_WOW64_64KEY);
  try
    Reg.RootKey := HKEY_LOCAL_MACHINE;
    { chave discreta; se preferir, ofusque o nome }
    if Reg.OpenKeyReadOnly('SOFTWARE\TSCfg\Sys') then
    begin
      s := Reg.ReadString('LastTick');
      if s <> '' then
        Result := StrToFloatDef(s, 0);
    end;
  finally
    Reg.Free;
  end;
end;

procedure TLicenciamento.GravarRelogioMaisAltoVisto(const AData: TDateTime);
var
  Reg: TRegistry;
begin
  if AData <= LerRelogioMaisAltoVisto then Exit; // so cresce
  Reg := TRegistry.Create(KEY_WRITE or KEY_WOW64_64KEY);
  try
    Reg.RootKey := HKEY_LOCAL_MACHINE;
    if Reg.OpenKey('SOFTWARE\TSCfg\Sys', True) then
      Reg.WriteString('LastTick', FloatToStr(AData));
  finally
    Reg.Free;
  end;
end;

function TLicenciamento.RelogioFoiManipulado: Boolean;
var
  maiorVisto: TDateTime;
begin
  maiorVisto := LerRelogioMaisAltoVisto;
  { tolerancia de 2 dias para fuso/ajustes legitimos }
  Result := (maiorVisto > 0) and (Now < (maiorVisto - 2));
end;

initialization
  Licenciamento := TLicenciamento.Create;
finalization
  Licenciamento.Free;
end.
