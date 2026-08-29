unit uAtivacaoOnline;

{ =====================================================================
  Ativacao ONLINE - fala com a API na sua VPS.
  Depende de uAtivacao (fingerprint, salvar licenca).
  Usa System.Net.HttpClient (Delphi XE8+ / 10.x).

  TS5: sem fallback de hardkey/Rockey2. SoftwareLiberado so retorna
  True se houver licenca web valida. Sem licenca = bloqueia (o
  chamador deve terminar o app - ver PATCH_Unit1.md).
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

{ Checagem de inicializacao: True se ha licenca web valida.
  Sem fallback - se False, o app deve bloquear a abertura. }
function SoftwareLiberado(out AMsg: string): Boolean;

implementation

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

function SoftwareLiberado(out AMsg: string): Boolean;
var
  r: TResultadoLicenca;
begin
  r := Licenciamento.VerificarLicenca;
  if r.Valida then
  begin
    AMsg := Format('Licenciado para %s. Expira em %s (%d dias).',
                   [r.Cliente, DateToStr(r.Expira), r.DiasRestantes]);
    Exit(True);
  end;

  AMsg := r.Mensagem;
  Result := False;
end;

end.
