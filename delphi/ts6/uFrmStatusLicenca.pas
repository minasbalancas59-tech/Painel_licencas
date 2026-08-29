unit uFrmStatusLicenca;

{ =====================================================================
  Tela de Status da Licenca - Total Scale
  ---------------------------------------------------------------------
  Mostra, com o sistema aberto, o estado completo da licenca ativa:
  cliente, produto, tipo (tier), nivel, validade, dias restantes,
  carencia, situacao e o codigo da maquina. Distingue licenca WEB de
  licenca por DONGLE.

  Botoes:
    - Atualizar: re-le a licenca do disco e atualiza os campos.
    - Reativar : abre a tela de ativacao web (digitar/renovar a chave).
    - Copiar codigo da maquina: para ativacao offline.
    - Fechar.

  A tela e construida por codigo (nao depende do .dfm no designer),
  entao basta adicionar esta unit ao projeto e chamar:

      uses uFrmStatusLicenca;
      ...
      MostrarStatusLicenca(licenca_web);   // passe sua flag global

  onde `licenca_web` e a variavel booleana que indica se a licenca
  atual e web (True) ou dongle (False).
  ===================================================================== }

interface

uses
  Winapi.Windows, System.SysUtils, System.Classes, System.DateUtils,
  Vcl.Forms, Vcl.Controls, Vcl.StdCtrls, Vcl.ExtCtrls, Vcl.Graphics,
  Vcl.Clipbrd,
  uAtivacao, uAtivacaoOnline;

type
  TFrmStatusLicenca = class(TForm)
  private
    FEhWeb: Boolean;
    pnTopo: TPanel;
    lbTitulo: TLabel;
    lbSituacao: TLabel;
    boxDados: TPanel;
    // labels de valor (preenchidos em Atualizar)
    vOrigem, vCliente, vProduto, vTier, vNivel,
    vEmissao, vValidade, vDias, vCarencia, vModulos, vMaquina: TLabel;
    btAtualizar, btReativar, btCopiar, btFechar: TButton;
    procedure MontarTela;
    function AddLinha(APai: TWinControl; ATopo: Integer;
      const ARotulo: string; out AValor: TLabel): Integer;
    procedure Atualizar(Sender: TObject);
    procedure Reativar(Sender: TObject);
    procedure CopiarMaquina(Sender: TObject);
    procedure FecharClick(Sender: TObject);
  public
    constructor CreateStatus(AOwner: TComponent; AEhWeb: Boolean);
  end;

procedure MostrarStatusLicenca(AEhWeb: Boolean);

implementation

uses
  uFrmAtivacao_exemplo, F_principal;

const
  COR_FUNDO   = $001A1714;  // grafite (BGR)
  COR_PAINEL  = $00262C1C;
  COR_TEXTO   = $00EFEBE6;
  COR_TEXTO2  = $0093A1AC;  // cinza secundario (BGR)
  COR_AMBAR   = $002BA9F0;  // ambar (BGR)
  COR_VERDE   = $006BB238;  // verde (BGR)
  COR_VERM    = $004E57E0;  // vermelho (BGR)

procedure MostrarStatusLicenca(AEhWeb: Boolean);
var
  f: TFrmStatusLicenca;
begin
  f := TFrmStatusLicenca.CreateStatus(Application, AEhWeb);
  try
    f.ShowModal;
  finally
    f.Free;
  end;
end;

constructor TFrmStatusLicenca.CreateStatus(AOwner: TComponent; AEhWeb: Boolean);
begin
  inherited CreateNew(AOwner);
  FEhWeb := AEhWeb;
  MontarTela;
  Atualizar(nil);
end;

function TFrmStatusLicenca.AddLinha(APai: TWinControl; ATopo: Integer;
  const ARotulo: string; out AValor: TLabel): Integer;
var
  lbR: TLabel;
begin
  lbR := TLabel.Create(Self);
  lbR.Parent := APai;
  lbR.Left := 16;
  lbR.Top := ATopo;
  lbR.Caption := ARotulo;
  lbR.Font.Color := $00ACA193;   // cinza secundario (BGR)
  lbR.Font.Size := 8;
  lbR.Font.Style := [];

  AValor := TLabel.Create(Self);
  AValor.Parent := APai;
  AValor.Left := 150;
  AValor.Top := ATopo - 2;
  AValor.Caption := '-';
  AValor.Font.Color := COR_TEXTO;
  AValor.Font.Size := 10;
  AValor.Font.Style := [fsBold];

  Result := ATopo + 26;
end;

procedure TFrmStatusLicenca.MontarTela;
var
  y: Integer;
begin
  Caption := 'Status da Licenca';
  BorderStyle := bsDialog;
  Position := poScreenCenter;
  ClientWidth := 460;
  ClientHeight := 500;
  Color := COR_FUNDO;

  // ---- topo: titulo + situacao ----
  pnTopo := TPanel.Create(Self);
  pnTopo.Parent := Self;
  pnTopo.Align := alTop;
  pnTopo.Height := 70;
  pnTopo.BevelOuter := bvNone;
  pnTopo.Color := COR_PAINEL;
  pnTopo.ParentBackground := False;

  lbTitulo := TLabel.Create(Self);
  lbTitulo.Parent := pnTopo;
  lbTitulo.Left := 16;
  lbTitulo.Top := 12;
  lbTitulo.Caption := 'STATUS DA LICENCA';
  lbTitulo.Font.Color := COR_TEXTO;
  lbTitulo.Font.Size := 12;
  lbTitulo.Font.Style := [fsBold];

  lbSituacao := TLabel.Create(Self);
  lbSituacao.Parent := pnTopo;
  lbSituacao.Left := 16;
  lbSituacao.Top := 40;
  lbSituacao.Caption := '...';
  lbSituacao.Font.Size := 10;
  lbSituacao.Font.Style := [fsBold];

  // ---- caixa de dados ----
  boxDados := TPanel.Create(Self);
  boxDados.Parent := Self;
  boxDados.Left := 14;
  boxDados.Top := 84;
  boxDados.Width := ClientWidth - 28;
  boxDados.Height := 330;
  boxDados.BevelOuter := bvNone;
  boxDados.Color := COR_PAINEL;
  boxDados.ParentBackground := False;

  y := 16;
  y := AddLinha(boxDados, y, 'Origem',           vOrigem);
  y := AddLinha(boxDados, y, 'Cliente',          vCliente);
  y := AddLinha(boxDados, y, 'Produto',          vProduto);
  y := AddLinha(boxDados, y, 'Tipo (tier)',      vTier);
  y := AddLinha(boxDados, y, 'Nivel',            vNivel);
  y := AddLinha(boxDados, y, 'Emitido em',       vEmissao);
  y := AddLinha(boxDados, y, 'Valido ate',       vValidade);
  y := AddLinha(boxDados, y, 'Dias restantes',   vDias);
  y := AddLinha(boxDados, y, 'Carencia (dias)',  vCarencia);
  y := AddLinha(boxDados, y, 'Modulos',          vModulos);
  y := AddLinha(boxDados, y, 'Codigo da maquina',vMaquina);

  // ---- botoes ----
  btAtualizar := TButton.Create(Self);
  btAtualizar.Parent := Self;
  btAtualizar.Left := 14;
  btAtualizar.Top := 428;
  btAtualizar.Width := 108;
  btAtualizar.Height := 32;
  btAtualizar.Caption := 'Atualizar';
  btAtualizar.OnClick := Atualizar;

  btReativar := TButton.Create(Self);
  btReativar.Parent := Self;
  btReativar.Left := 128;
  btReativar.Top := 428;
  btReativar.Width := 120;
  btReativar.Height := 32;
  btReativar.Caption := 'Reativar / Renovar';
  btReativar.OnClick := Reativar;

  btCopiar := TButton.Create(Self);
  btCopiar.Parent := Self;
  btCopiar.Left := 254;
  btCopiar.Top := 428;
  btCopiar.Width := 96;
  btCopiar.Height := 32;
  btCopiar.Caption := 'Copiar codigo';
  btCopiar.OnClick := CopiarMaquina;

  btFechar := TButton.Create(Self);
  btFechar.Parent := Self;
  btFechar.Left := 356;
  btFechar.Top := 428;
  btFechar.Width := 90;
  btFechar.Height := 32;
  btFechar.Caption := 'Fechar';
  btFechar.OnClick := FecharClick;
end;

procedure TFrmStatusLicenca.Atualizar(Sender: TObject);
var
  r: TResultadoLicenca;
  dias: Integer;
  txt: string;
  sit: Integer;
begin
  r := Licenciamento.VerificarLicenca;

  // codigo da maquina sempre disponivel
  vMaquina.Caption := Licenciamento.ObterFingerprint;

  if FEhWeb and r.Valida then
  begin
    vOrigem.Caption   := 'Licenca web';
    vCliente.Caption  := r.Cliente;
    vProduto.Caption  := UpperCase(r.Produto);
    if r.Tier <> '' then
      vTier.Caption := r.Tier
    else
      vTier.Caption := '(unico)';
    if r.Nivel > 0 then
      vNivel.Caption := IntToStr(r.Nivel)
    else
      vNivel.Caption := '-';
    vValidade.Caption := DateToStr(r.Expira);
    vEmissao.Caption  := '-';  // emissao nao vem no record; opcional
    vModulos.Caption  := r.Modulos;

    if r.Carencia > 0 then
      vCarencia.Caption := IntToStr(r.Carencia)
    else
      vCarencia.Caption := '-';

    // situacao (cor + texto)
    sit := Licenciamento.SituacaoLicenca(dias, txt);
    case sit of
      SIT_OK:
        begin
          lbSituacao.Caption := 'Licenca ativa';
          lbSituacao.Font.Color := COR_VERDE;
          vDias.Caption := IntToStr(r.DiasRestantes) + ' dia(s)';
          vDias.Font.Color := COR_TEXTO;
        end;
      SIT_PROXIMO:
        begin
          lbSituacao.Caption := txt;   // "Sua licenca expira em N dias"
          lbSituacao.Font.Color := COR_AMBAR;
          vDias.Caption := IntToStr(r.DiasRestantes) + ' dia(s)';
          vDias.Font.Color := COR_AMBAR;
        end;
      SIT_CARENCIA:
        begin
          lbSituacao.Caption := txt;   // "Licenca expirada - carencia: N dias"
          lbSituacao.Font.Color := COR_VERM;
          vDias.Caption := 'expirada (em carencia)';
          vDias.Font.Color := COR_VERM;
        end;
    else
      lbSituacao.Caption := 'Licenca bloqueada';
      lbSituacao.Font.Color := COR_VERM;
      vDias.Caption := '0';
      vDias.Font.Color := COR_VERM;
    end;
  end
  else if (not FEhWeb) then
  begin
    // licenca por DONGLE (Rockey2)
    vOrigem.Caption   := 'Dongle Rockey2';
    vCliente.Caption  := '-';
    vProduto.Caption  := 'Total Scale';
    vTier.Caption     := tplicensa;        // ex: "Extreme"
    vNivel.Caption    := 'maximo';
    vValidade.Caption := 'sem expiracao';
    vEmissao.Caption  := '-';
    vDias.Caption     := '-';
    vCarencia.Caption := '-';
    vModulos.Caption  := 'todos';
    lbSituacao.Caption := 'Licenca ativa (dongle)';
    lbSituacao.Font.Color := COR_VERDE;
  end
  else
  begin
    // sem licenca valida
    vOrigem.Caption   := '-';
    vCliente.Caption  := '-';
    vProduto.Caption  := '-';
    vTier.Caption     := '-';
    vNivel.Caption    := '-';
    vValidade.Caption := '-';
    vEmissao.Caption  := '-';
    vDias.Caption     := '-';
    vCarencia.Caption := '-';
    vModulos.Caption  := '-';
    lbSituacao.Caption := 'Sem licenca ativa: ' + r.Mensagem;
    lbSituacao.Font.Color := COR_VERM;
  end;
end;

procedure TFrmStatusLicenca.Reativar(Sender: TObject);
begin
  // abre a tela de ativacao web (digitar/renovar chave)
  FrmAtivacao := TFrmAtivacao.Create(Self);
  try
    FrmAtivacao.ShowModal;
  finally
    FrmAtivacao.Free;
    FrmAtivacao := nil;
  end;
  // apos a tentativa, marca como web e reatualiza
  FEhWeb := True;
  Atualizar(nil);
end;

procedure TFrmStatusLicenca.CopiarMaquina(Sender: TObject);
begin
  Clipboard.AsText := Licenciamento.ObterFingerprint;
  MessageBox(Handle,
    'Codigo da maquina copiado. Envie ao suporte para ativacao offline.',
    'Status da Licenca', MB_ICONINFORMATION);
end;

procedure TFrmStatusLicenca.FecharClick(Sender: TObject);
begin
  Close;
end;

end.
