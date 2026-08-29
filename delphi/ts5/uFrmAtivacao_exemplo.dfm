object FrmAtivacao: TFrmAtivacao
  Left = 0
  Top = 0
  BorderStyle = bsDialog
  Caption = 'Ativa'#231#227'o de Licen'#231'a - Total Scale'
  ClientHeight = 620
  ClientWidth = 560
  Color = 1709844
  Font.Charset = DEFAULT_CHARSET
  Font.Color = 15723494
  Font.Height = -12
  Font.Name = 'Segoe UI'
  Font.Style = []
  Position = poScreenCenter
  OnShow = FormShow
  TextHeight = 15
  object pnlTopo: TPanel
    Left = 0
    Top = 0
    Width = 560
    Height = 62
    Align = alTop
    BevelOuter = bvNone
    Color = 2498844
    ParentBackground = False
    TabOrder = 0
    object lblMarca: TLabel
      Left = 24
      Top = 15
      Width = 68
      Height = 25
      Caption = 'TOTAL'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 15723494
      Font.Height = -19
      Font.Name = 'Consolas'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object lblMarca2: TLabel
      Left = 91
      Top = 15
      Width = 66
      Height = 25
      Caption = 'SCALE'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 2861552
      Font.Height = -19
      Font.Name = 'Consolas'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object lblSub: TLabel
      Left = 172
      Top = 24
      Width = 145
      Height = 13
      Caption = #183'  ATIVA'#199#195'O DE LICEN'#199'A'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = []
      ParentFont = False
    end
    object shLinha: TShape
      Left = 0
      Top = 61
      Width = 560
      Height = 1
      Brush.Color = 4340273
      Pen.Style = psClear
    end
  end
  object pnlMaquina: TPanel
    Left = 24
    Top = 82
    Width = 512
    Height = 120
    BevelOuter = bvNone
    Color = 2498844
    ParentBackground = False
    TabOrder = 1
    object lblTitMaquina: TLabel
      Left = 20
      Top = 16
      Width = 128
      Height = 13
      Caption = 'C'#211'DIGO DA M'#193'QUINA'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object lblAjudaMaquina: TLabel
      Left = 20
      Top = 34
      Width = 361
      Height = 13
      Caption = 'Informe este c'#243'digo ao suporte para gerar sua ativa'#231#227'o offline.'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = []
      ParentFont = False
    end
    object pnlFPBox: TPanel
      Left = 20
      Top = 58
      Width = 360
      Height = 42
      BevelOuter = bvNone
      Color = 1709844
      ParentBackground = False
      TabOrder = 0
      object lblFingerprint: TLabel
        Left = 16
        Top = 11
        Width = 190
        Height = 21
        Caption = 'XXXX-XXXX-XXXX-XXXX'
        Font.Charset = DEFAULT_CHARSET
        Font.Color = 7062512
        Font.Height = -16
        Font.Name = 'Consolas'
        Font.Style = [fsBold]
        ParentFont = False
      end
    end
    object btnCopiarFP: TButton
      Left = 392
      Top = 58
      Width = 100
      Height = 42
      Caption = 'Copiar'
      TabOrder = 1
      OnClick = btnCopiarFPClick
    end
  end
  object pnlOnline: TPanel
    Left = 24
    Top = 216
    Width = 512
    Height = 150
    BevelOuter = bvNone
    Color = 2498844
    ParentBackground = False
    TabOrder = 2
    object lblTitOnline: TLabel
      Left = 20
      Top = 16
      Width = 116
      Height = 13
      Caption = 'ATIVA'#199#195'O ONLINE'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object lblAjudaOnline: TLabel
      Left = 20
      Top = 34
      Width = 316
      Height = 13
      Caption = 'Digite a chave recebida e clique em Ativar (requer internet).'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = []
      ParentFont = False
    end
    object lblChave: TLabel
      Left = 20
      Top = 60
      Width = 99
      Height = 13
      Caption = 'CHAVE DA LICEN'#199'A'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = []
      ParentFont = False
    end
    object edtChave: TEdit
      Left = 20
      Top = 78
      Width = 360
      Height = 30
      CharCase = ecUpperCase
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 15723494
      Font.Height = -15
      Font.Name = 'Consolas'
      Font.Style = []
      ParentFont = False
      TabOrder = 0
      TextHint = 'TS6X-XXXX-XXXX-XXXX'
    end
    object btnAtivarOnline: TButton
      Left = 392
      Top = 78
      Width = 100
      Height = 30
      Caption = 'Ativar'
      Default = True
      TabOrder = 1
      OnClick = btnAtivarOnlineClick
    end
  end
  object pnlOffline: TPanel
    Left = 24
    Top = 380
    Width = 512
    Height = 176
    BevelOuter = bvNone
    Color = 2498844
    ParentBackground = False
    TabOrder = 3
    object lblTitOffline: TLabel
      Left = 20
      Top = 16
      Width = 121
      Height = 13
      Caption = 'ATIVA'#199#195'O OFFLINE'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object lblAjudaOffline: TLabel
      Left = 20
      Top = 34
      Width = 380
      Height = 13
      Caption = 'Cole o c'#243'digo de ativa'#231#227'o enviado pelo suporte (n'#227'o requer internet).'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = []
      ParentFont = False
    end
    object memoCodigoOff: TMemo
      Left = 20
      Top = 56
      Width = 472
      Height = 76
      Color = 1709844
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 7062512
      Font.Height = -11
      Font.Name = 'Consolas'
      Font.Style = []
      ParentFont = False
      ScrollBars = ssVertical
      TabOrder = 0
      WordWrap = True
    end
    object btnAtivarOff: TButton
      Left = 20
      Top = 140
      Width = 200
      Height = 30
      Caption = 'Ativar com c'#243'digo offline'
      TabOrder = 1
      OnClick = btnAtivarOffClick
    end
  end
  object pnlStatus: TPanel
    Left = 0
    Top = 578
    Width = 560
    Height = 42
    Align = alBottom
    BevelOuter = bvNone
    Color = 1775378
    ParentBackground = False
    TabOrder = 4
    object lblStatus: TLabel
      Left = 24
      Top = 14
      Width = 108
      Height = 13
      Caption = 'Aguardando ativa'#231#227'o...'
      Font.Charset = DEFAULT_CHARSET
      Font.Color = 11313555
      Font.Height = -11
      Font.Name = 'Segoe UI'
      Font.Style = []
      ParentFont = False
    end
  end
end
