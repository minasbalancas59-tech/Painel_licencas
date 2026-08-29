unit uAtivacaoOnline;

{ =====================================================================
  Ativacao ONLINE - fala com a API na sua VPS.
  Depende de uAtivacao (fingerprint, salvar licenca).
  Usa System.Net.HttpClient (Delphi XE8+ / 10.x).
  ===================================================================== }

interface

uses
  System.SysUtils, System.Classes, System.JSON,
  System.Net.HttpClient, System.Net.URLClient,
  uAtivacao;

const
  { URL da API na sua VPS. HTTPS obrigatorio em producao. }
  URL_API_ATIVAR = 'https://licencas.totalscale.com.br/api/ativar.php';

type
  TRespostaAtivacao = record
    Ok: Boolean;
    Mensagem: string;
    Cliente: string;
    Expira: string;
  end;

{ Ativa online: manda chave + fingerprint, recebe e salva a licenca. }
function AtivarOnline(const AChave: string): TRespostaAtivacao;

{ Fluxo de inicializacao completo com fallback para Rockey2.
  Retorna True se o software pode rodar. }
function SoftwareLiberado(out AMsg: string): Boolean;

implementation

{ declare aqui sua funcao existente de checagem do Rockey2, ou
  inclua a unit correspondente. Ex.: uses uRockey2; ... }

uses F_principal;
function Rockey2Presente: Boolean; forward;

function AtivarOnline(const AChave: string): TRespostaAtivacao;
var
  http: THTTPClient;
  corpo: TStringStream;
  reqJson: TJSONObject;
  resp: IHTTPResponse;
  jo: TJSONObject;
  licenca: string;
  valida: TResultadoLicenca;
begin
  Result := Default(TRespostaAtivacao);

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
    http.ConnectionTimeout := 15000;
    http.ResponseTimeout   := 15000;
    try
      resp := http.Post(URL_API_ATIVAR, corpo,
        nil, [TNameValuePair.Create('Content-Type','application/json')]);
    except
      on E: Exception do
      begin
        Result.Mensagem := 'Sem conexao com o servidor de licencas. ' +
                           'Verifique a internet ou use ativacao offline.';
        corpo.Free;
        Exit;
      end;
    end;

    jo := TJSONObject.ParseJSONValue(resp.ContentAsString(TEncoding.UTF8)) as TJSONObject;
    if jo = nil then
    begin
      Result.Mensagem := 'Resposta invalida do servidor.';
      corpo.Free;
      Exit;
    end;
    try
      if jo.GetValue<Boolean>('ok', False) then
      begin
        licenca := jo.GetValue<string>('licenca','');
        { valida localmente antes de salvar (garante que confere com a maquina) }
        valida := Licenciamento.ValidarLicencaAssinada(licenca);
        if valida.Valida then
        begin
          Licenciamento.SalvarLicenca(licenca);
          Result.Ok := True;
          Result.Cliente := valida.Cliente;
          Result.Expira  := DateToStr(valida.Expira);
          Result.Mensagem := 'Ativado com sucesso.';
        end
        else
          Result.Mensagem := 'Licenca recebida nao confere: ' + valida.Mensagem;
      end
      else
        Result.Mensagem := jo.GetValue<string>('erro','Ativacao negada.');
    finally
      jo.Free;
    end;
  finally
    http.Free;
    corpo.Free;
  end;
end;

function Rockey2Presente: Boolean;
begin
   Result := Principal.verificahardkey;
end;

function SoftwareLiberado(out AMsg: string): Boolean;
var
  r: TResultadoLicenca;
begin
  { 1) licenca web (offline, sem internet) }
  r := Licenciamento.VerificarLicenca;
  if r.Valida then
  begin
    AMsg := Format('Licenciado para %s. Expira em %s (%d dias).',
                   [r.Cliente, DateToStr(r.Expira), r.DiasRestantes]);
    Exit(True);
  end;

  { 2) fallback: Rockey2 durante a transicao }
  if Rockey2Presente then
  begin
    AMsg := 'Licenciado via dongle Rockey2.';
    Exit(True);
  end;

  { 3) nada valido -> abrir tela de ativacao }
  AMsg := r.Mensagem;
  Result := False;
end;

end.
