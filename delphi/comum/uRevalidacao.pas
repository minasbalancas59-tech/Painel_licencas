unit uRevalidacao;

{ =====================================================================
  Revalidacao online periodica - Total Scale
  ---------------------------------------------------------------------
  Faz a licenca web "ligar pra casa" a cada N dias para confirmar que
  ainda e valida (nao foi revogada no painel). Reaproveita o mesmo
  endpoint ativar.php.

  Regra (configuravel nas constantes abaixo):
    - REVALIDA_DIAS = 7  -> tenta revalidar a cada 7 dias
    - PRAZO_EXTRA   = 7  -> se estiver SEM internet, tolera +7 dias
                            antes de bloquear
    - Se o servidor responder "revogada/expirada" -> bloqueia na hora
    - Se responder "ok" -> renova a licenca local e zera o contador

  Guarda no registro (HKLM\SOFTWARE\TSCfg\Sys):
    - ChaveLic   : a chave TS6X-... (necessaria para revalidar)
    - UltRevalid : data/hora da ultima revalidacao BEM-SUCEDIDA

  COMO USAR:
    1) Ao ATIVAR (online ou offline), chame GravarChaveLicenca(chave)
       para o sistema saber qual chave revalidar depois.
    2) Na inicializacao, depois de liberar a licenca web, chame
       RevalidarSeNecessario. Ela retorna:
         rvOK        -> licenca confirmada (ou ainda dentro do ciclo)
         rvBloqueio  -> revogada/expirada no servidor -> NAO deixe abrir
         rvSemRede   -> sem internet mas ainda dentro do prazo extra
       Trate rvBloqueio bloqueando o sistema.
  ===================================================================== }

interface

uses
  Winapi.Windows, System.SysUtils, System.Classes, System.DateUtils,
  System.Win.Registry, System.JSON,
  System.Net.HttpClient, System.Net.URLClient,
  uAtivacao;

type
  TResultadoRevalida = (rvOK, rvBloqueio, rvSemRede);

const
  REVALIDA_DIAS = 7;   // revalidar a cada 7 dias
  PRAZO_EXTRA   = 7;   // dias extras tolerados sem internet
  URL_API_ATIVAR_REV = 'https://licencas.totalscale.com.br/api/ativar.php';

{ grava a chave da licenca (chame na ativacao) }
procedure GravarChaveLicenca(const AChave: string);
function  LerChaveLicenca: string;

{ revalida se ja passou o ciclo; ver comentario no topo }
function RevalidarSeNecessario(out AMsg: string): TResultadoRevalida;

implementation

const
  REG_KEY = 'SOFTWARE\TSCfg\Sys';

{ ---- registro: chave e data da ultima revalidacao ------------------ }

procedure GravarChaveLicenca(const AChave: string);
var
  Reg: TRegistry;
begin
  Reg := TRegistry.Create(KEY_WRITE or KEY_WOW64_64KEY);
  try
    Reg.RootKey := HKEY_LOCAL_MACHINE;
    if Reg.OpenKey(REG_KEY, True) then
      Reg.WriteString('ChaveLic', AChave);
  finally
    Reg.Free;
  end;
end;

function LerChaveLicenca: string;
var
  Reg: TRegistry;
begin
  Result := '';
  Reg := TRegistry.Create(KEY_READ or KEY_WOW64_64KEY);
  try
    Reg.RootKey := HKEY_LOCAL_MACHINE;
    if Reg.OpenKeyReadOnly(REG_KEY) and Reg.ValueExists('ChaveLic') then
      Result := Reg.ReadString('ChaveLic');
  finally
    Reg.Free;
  end;
end;

function LerUltimaRevalidacao: TDateTime;
var
  Reg: TRegistry;
  s: string;
begin
  Result := 0;
  Reg := TRegistry.Create(KEY_READ or KEY_WOW64_64KEY);
  try
    Reg.RootKey := HKEY_LOCAL_MACHINE;
    if Reg.OpenKeyReadOnly(REG_KEY) and Reg.ValueExists('UltRevalid') then
    begin
      s := Reg.ReadString('UltRevalid');
      Result := StrToFloatDef(s, 0);
    end;
  finally
    Reg.Free;
  end;
end;

procedure GravarUltimaRevalidacao(const AData: TDateTime);
var
  Reg: TRegistry;
begin
  Reg := TRegistry.Create(KEY_WRITE or KEY_WOW64_64KEY);
  try
    Reg.RootKey := HKEY_LOCAL_MACHINE;
    if Reg.OpenKey(REG_KEY, True) then
      Reg.WriteString('UltRevalid', FloatToStr(AData));
  finally
    Reg.Free;
  end;
end;

{ ---- chamada ao servidor (mesmo endpoint da ativacao) -------------- }

function ConsultarServidor(const AChave: string;
  out ARevogadaOuExpirada: Boolean; out ALicencaNova: string): Boolean;
{ Result = conseguiu falar com o servidor (True) ou nao (False/sem rede).
  ARevogadaOuExpirada = servidor negou (revogada/expirada).
  ALicencaNova = licenca assinada renovada, quando ok. }
var
  http: THTTPClient;
  corpo: TStringStream;
  reqJson, jo: TJSONObject;
  resp: IHTTPResponse;
begin
  Result := False;
  ARevogadaOuExpirada := False;
  ALicencaNova := '';

  reqJson := TJSONObject.Create;
  try
    reqJson.AddPair('chave', Trim(AChave));
    reqJson.AddPair('fingerprint', Licenciamento.ObterFingerprint);
    corpo := TStringStream.Create(reqJson.ToJSON, TEncoding.UTF8);
  finally
    reqJson.Free;
  end;

  http := THTTPClient.Create;
  try
    http.ConnectionTimeout := 12000;
    http.ResponseTimeout   := 12000;
    try
      resp := http.Post(URL_API_ATIVAR_REV, corpo, nil,
        [TNameValuePair.Create('Content-Type','application/json')]);
    except
      // sem internet / servidor fora -> nao conseguiu revalidar
      corpo.Free;
      Exit(False);
    end;

    Result := True;  // falamos com o servidor

    jo := TJSONObject.ParseJSONValue(resp.ContentAsString(TEncoding.UTF8)) as TJSONObject;
    if jo = nil then
    begin
      // resposta ilegivel: trata como "nao conseguiu" (nao bloqueia)
      corpo.Free;
      Exit(False);
    end;
    try
      if jo.GetValue<Boolean>('ok', False) then
      begin
        // licenca confirmada; guarda a versao renovada
        ALicencaNova := jo.GetValue<string>('licenca','');
      end
      else
      begin
        // servidor negou. Se foi revogada/expirada, bloqueia.
        // (status 403 no ativar.php). Mensagens de "outra maquina" tambem
        // sao negacao definitiva -> bloqueia.
        ARevogadaOuExpirada := True;
      end;
    finally
      jo.Free;
    end;
  finally
    http.Free;
    corpo.Free;
  end;
end;

{ ---- funcao principal --------------------------------------------- }

function RevalidarSeNecessario(out AMsg: string): TResultadoRevalida;
var
  chave, licNova: string;
  ultima: TDateTime;
  diasDesde: Integer;
  revogada, falou: Boolean;
begin
  AMsg := '';
  chave := LerChaveLicenca;

  // sem chave gravada: nao da para revalidar (licenca antiga/dongle).
  // Nao bloqueia -> segue normal.
  if chave = '' then
    Exit(rvOK);

  ultima := LerUltimaRevalidacao;
  if ultima = 0 then
  begin
    // primeira vez: considera "agora" como marco inicial e segue
    GravarUltimaRevalidacao(Now);
    Exit(rvOK);
  end;

  diasDesde := DaysBetween(Now, ultima);
  if Now < ultima then diasDesde := 0;   // relogio adiantado/atrasado

  // ainda dentro do ciclo: nao precisa revalidar agora
  if diasDesde < REVALIDA_DIAS then
    Exit(rvOK);

  // passou o ciclo -> tenta revalidar online
  falou := ConsultarServidor(chave, revogada, licNova);

  if falou then
  begin
    if revogada then
    begin
      AMsg := 'Licenca revogada ou invalida. Contate o suporte.';
      Exit(rvBloqueio);
    end;

    // ok: renova a licenca local (se veio nova) e zera o contador
    if licNova <> '' then
      Licenciamento.SalvarLicenca(licNova);
    GravarUltimaRevalidacao(Now);
    Exit(rvOK);
  end
  else
  begin
    // nao conseguiu falar com o servidor (sem internet)
    // tolera ate PRAZO_EXTRA dias alem do ciclo
    if diasDesde <= (REVALIDA_DIAS + PRAZO_EXTRA) then
    begin
      AMsg := Format('Sem conexao para revalidar a licenca. ' +
        'Conecte a internet em ate %d dia(s).',
        [(REVALIDA_DIAS + PRAZO_EXTRA) - diasDesde]);
      Exit(rvSemRede);
    end
    else
    begin
      AMsg := 'Licenca nao revalidada no prazo. ' +
              'Conecte a internet para continuar usando o sistema.';
      Exit(rvBloqueio);
    end;
  end;
end;

end.
