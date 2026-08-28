unit uFrmAtivacao_exemplo;

{ =====================================================================
  EXEMPLO de tela de ativacao (esqueleto).
  Mostra o "Codigo da Maquina", permite ativar online (digitando a
  chave) ou colar o "Codigo de Ativacao" gerado no painel (offline).

  Adapte os nomes dos componentes ao seu .dfm. Este arquivo serve de
  referencia de como amarrar a UI as units uAtivacao / uAtivacaoOnline.
  ===================================================================== }

interface

uses
  Winapi.Windows, Winapi.Messages, System.SysUtils, System.Classes,
  Vcl.Controls, Vcl.Forms, Vcl.StdCtrls, Vcl.ExtCtrls, Vcl.Clipbrd,
  uAtivacao, uAtivacaoOnline;

type
  TFrmAtivacao = class(TForm)
    pnlTopo: TPanel;
    lblMarca: TLabel;
    lblMarca2: TLabel;
    lblSub: TLabel;
    shLinha: TShape;
    pnlMaquina: TPanel;
    lblTitMaquina: TLabel;
    lblAjudaMaquina: TLabel;
    pnlFPBox: TPanel;
    lblFingerprint: TLabel;      // exibe o codigo da maquina
    btnCopiarFP: TButton;
    pnlOnline: TPanel;
    lblTitOnline: TLabel;
    lblAjudaOnline: TLabel;
    lblChave: TLabel;
    edtChave: TEdit;             // chave TS6X-....
    btnAtivarOnline: TButton;
    pnlOffline: TPanel;
    lblTitOffline: TLabel;
    lblAjudaOffline: TLabel;
    memoCodigoOff: TMemo;        // colar codigo de ativacao offline
    btnAtivarOff: TButton;
    pnlStatus: TPanel;
    lblStatus: TLabel;
    procedure FormShow(Sender: TObject);
    procedure btnCopiarFPClick(Sender: TObject);
    procedure btnAtivarOnlineClick(Sender: TObject);
    procedure btnAtivarOffClick(Sender: TObject);
  end;

var
  FrmAtivacao: TFrmAtivacao;

implementation

{$R *.dfm}

procedure TFrmAtivacao.FormShow(Sender: TObject);
begin
  // mostra o codigo da maquina para o cliente informar ao suporte
  lblFingerprint.Caption := Licenciamento.ObterFingerprint;
  lblStatus.Caption := 'Aguardando ativacao...';
end;

procedure TFrmAtivacao.btnCopiarFPClick(Sender: TObject);
begin
  Clipboard.AsText := Licenciamento.ObterFingerprint;
  lblStatus.Caption := 'Codigo da maquina copiado. Envie ao suporte.';
end;

procedure TFrmAtivacao.btnAtivarOnlineClick(Sender: TObject);
var
  r: TRespostaAtivacao;
begin
  lblStatus.Caption := 'Conectando ao servidor...';
  Application.ProcessMessages;

  r := AtivarOnline(edtChave.Text);
  if r.Ok then
  begin
    lblStatus.Caption := Format('Ativado! Cliente: %s. Expira em %s.',
                                [r.Cliente, r.Expira]);
    ModalResult := mrOk;   // libera o app
  end
  else
    lblStatus.Caption := 'Falha: ' + r.Mensagem;
end;

procedure TFrmAtivacao.btnAtivarOffClick(Sender: TObject);
var
  res: TResultadoLicenca;
  codigo: string;
begin
  codigo := Trim(memoCodigoOff.Text);
  res := Licenciamento.ValidarLicencaAssinada(codigo);
  if res.Valida then
  begin
    Licenciamento.SalvarLicenca(codigo);
    lblStatus.Caption := Format('Ativado offline! Cliente: %s. Expira em %s.',
                                [res.Cliente, DateToStr(res.Expira)]);
    ModalResult := mrOk;
  end
  else
    lblStatus.Caption := 'Codigo invalido: ' + res.Mensagem;
end;

end.
