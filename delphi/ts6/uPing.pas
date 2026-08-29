unit uPing;

{ =====================================================================
  Registro de uso - Total Scale
  ---------------------------------------------------------------------
  Informa ao servidor que a maquina esta em uso. Alimenta a tela de
  Maquinas do painel: quem abriu, quando, com que frequencia e por
  quanto tempo.

  Tres tipos de sinal:

    abertura   - uma vez, quando o sistema abre
    presenca   - a cada 15 minutos, enquanto estiver aberto
    fechamento - uma vez, ao fechar normalmente

  Por que o sinal de presenca existe: sem ele daria para saber a HORA
  em que o sistema abriu, nunca quanto tempo ficou aberto. O painel
  reconstroi as sessoes a partir desses sinais - silencio prolongado
  encerra a sessao. Isso tambem cobre o caso do PC desligado na tomada,
  que nunca chega a mandar o fechamento.

  COMO USAR - duas linhas no .dpr:

        uses uPing;
        ...
        IniciarMonitoramento;      // antes do Application.Run
        Application.Run;

  O fechamento e enviado sozinho, na finalizacao da unit.

  Decisoes de projeto:

  1. Tudo em THREAD separada, e o sistema nunca espera por ela. O
     primeiro sinal ainda aguarda alguns segundos para nao concorrer
     com a carga inicial (banco, formularios, perifericos).

  2. Falha em silencio. Isto e informacao gerencial, nao licenciamento:
     erro aqui jamais pode virar mensagem ao operador ou travar o uso.

  3. A thread NAO usa o objeto Licenciamento. Os dados sao lidos na
     thread principal, antes de comecar. Alem de nao disputar disco na
     abertura, isso evita violacao de acesso: a finalizacao do
     uAtivacao destroi o Licenciamento no fim do programa.

  4. A espera entre sinais usa um evento, nao Sleep puro: assim fechar
     o sistema nao fica preso esperando 15 minutos.
  ===================================================================== }

interface

uses
  System.SysUtils, System.Classes;

const
  URL_API_PING = 'https://licencas.totalscale.com.br/api/ping.php';
  MINUTOS_PRESENCA = 15;

{ Inicia o monitoramento: manda a abertura e passa a sinalizar presenca.
  Chamar UMA vez, antes do Application.Run. }
procedure IniciarMonitoramento;

{ Encerra e manda o fechamento. Chamado sozinho na finalizacao; so use
  manualmente se precisar parar antes. }
procedure EncerrarMonitoramento;

{ chave gravada dentro do licenca.dat ('' quando nao ha licenca web) }
function ChaveDaLicencaInstalada: string;

implementation

uses
  System.JSON, System.NetEncoding, System.SyncObjs,
  System.Net.HttpClient, System.Net.URLClient,
  uAtivacao;

type
  TMonitorThread = class(TThread)
  private
    FChave, FOrigem, FFingerprint, FNome, FUsuario, FSO: string;
    FParar: TEvent;
    procedure Enviar(const ATipo: string);
  protected
    procedure Execute; override;
  public
    constructor Create;
    destructor Destroy; override;
    procedure Parar;
  end;

var
  GMonitor: TMonitorThread = nil;

{ Extrai o campo "chave" do payload assinado (payload.assinatura).
  Nao valida a assinatura aqui de proposito: quem valida licenca e o
  uAtivacao. Aqui so queremos um rotulo para o painel.

  Nao se usa LerChaveLicenca (uRevalidacao): o GravarChaveLicenca
  correspondente nunca chegou a ser chamado no sistema, e ele grava em
  HKEY_LOCAL_MACHINE, que exige administrador. O arquivo de licenca e a
  fonte que sempre existe quando ha licenca web. }
function ChaveDaLicencaInstalada: string;
var
  Lic: string;
  Partes: TArray<string>;
  Bytes: TBytes;
  Jo: TJSONObject;
begin
  Result := '';
  try
    Lic := Licenciamento.CarregarLicenca;
    if Trim(Lic) = '' then
      Exit;

    Partes := Lic.Split(['.']);
    if Length(Partes) <> 2 then
      Exit;

    Bytes := TNetEncoding.Base64URL.DecodeStringToBytes(Partes[0]);
    Jo := TJSONObject.ParseJSONValue(TEncoding.UTF8.GetString(Bytes)) as TJSONObject;
    if Jo = nil then
      Exit;
    try
      Result := Jo.GetValue<string>('chave', '');
    finally
      Jo.Free;
    end;
  except
    Result := '';
  end;
end;

{ ------------------------------------------------------------------ }

constructor TMonitorThread.Create;
begin
  // Le tudo AQUI, ainda na thread principal (ver decisao 3 no topo).
  FChave := Trim(ChaveDaLicencaInstalada);
  if FChave = '' then
    FOrigem := 'dongle'      // sem licenca web = ainda no Rockey2
  else
    FOrigem := 'licenca';

  FFingerprint := Licenciamento.ObterFingerprint;
  FNome        := Licenciamento.ObterNomeMaquina;
  FUsuario     := Licenciamento.ObterUsuarioWindows;
  FSO          := Licenciamento.ObterVersaoWindows;

  FParar := TEvent.Create(nil, True, False, '');
  FreeOnTerminate := False;   // a finalizacao precisa esperar por ela
  inherited Create(False);
end;

destructor TMonitorThread.Destroy;
begin
  inherited;
  FParar.Free;
end;

procedure TMonitorThread.Parar;
begin
  Terminate;
  FParar.SetEvent;   // acorda a espera na hora
end;

procedure TMonitorThread.Enviar(const ATipo: string);
var
  http: THTTPClient;
  corpo: TStringStream;
  Jo: TJSONObject;
  Texto: string;
begin
  try
    Jo := TJSONObject.Create;
    try
      Jo.AddPair('chave', FChave);
      Jo.AddPair('fingerprint', FFingerprint);
      Jo.AddPair('maq_nome', FNome);
      Jo.AddPair('maq_usuario', FUsuario);
      Jo.AddPair('maq_so', FSO);
      Jo.AddPair('origem', FOrigem);
      Jo.AddPair('tipo', ATipo);
      Texto := Jo.ToJSON;
    finally
      Jo.Free;
    end;

    http := THTTPClient.Create;
    corpo := TStringStream.Create(Texto, TEncoding.UTF8);
    try
      http.ConnectionTimeout := 8000;
      http.ResponseTimeout := 8000;
      http.Post(URL_API_PING, corpo, nil,
        [TNetHeader.Create('Content-Type', 'application/json; charset=utf-8')]);
    finally
      corpo.Free;
      http.Free;
    end;
  except
    // sem internet, servidor fora, proxy: ignora
  end;
end;

procedure TMonitorThread.Execute;
begin
  // deixa o sistema terminar de abrir antes do primeiro sinal
  if FParar.WaitFor(4000) = wrSignaled then
    Exit;

  Enviar('abertura');

  while not Terminated do
  begin
    if FParar.WaitFor(MINUTOS_PRESENCA * 60 * 1000) = wrSignaled then
      Break;
    if not Terminated then
      Enviar('presenca');
  end;
end;

{ ------------------------------------------------------------------ }

procedure IniciarMonitoramento;
begin
  if GMonitor <> nil then
    Exit;   // ja iniciado: nunca dois monitores
  try
    GMonitor := TMonitorThread.Create;
  except
    GMonitor := nil;   // silencioso, como o resto
  end;
end;

procedure EncerrarMonitoramento;
var
  Mon: TMonitorThread;
begin
  if GMonitor = nil then
    Exit;
  Mon := GMonitor;
  GMonitor := nil;
  try
    Mon.Parar;
    // espera curta: fechar o sistema nao pode demorar por causa disto
    Mon.WaitFor;
    // o fechamento vai daqui, com os dados que a thread ja tinha
    Mon.Enviar('fechamento');
  except
    // ignora
  end;
  Mon.Free;
end;

initialization

finalization
  EncerrarMonitoramento;

end.
