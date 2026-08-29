# Patch do Unit1.pas — migração para licença web (sem hardkey)

Três pontos de mudança no `Unit1.pas`. Fora deles, nada mais precisa ser
tocado: as ~40 leituras de `licensa`, `tplicensa`, `tplicensaint` e
`hardkeyautomacao` espalhadas pelo resto do arquivo continuam
funcionando sem alteração, porque essas variáveis continuam existindo
e sendo preenchidas — só que agora a partir da licença web, não do
dongle.

## 0) Adicionar aos `uses`

No topo do `Unit1.pas` (seção `implementation` ou `interface`, onde já
estão os outros `uses`), adicione:

```pascal
uses
  ...,
  uAtivacao, uAtivacaoOnline, uRevalidacao;
```

E no projeto (`fili1.dpr`), adicione as novas units na cláusula `uses`
do programa (mesmo padrão das existentes):

```pascal
uAtivacao in 'uAtivacao.pas',
uAtivacaoOnline in 'uAtivacaoOnline.pas',
uEd25519 in 'uEd25519.pas',
uRevalidacao in 'uRevalidacao.pas',
uPing in 'uPing.pas',
uFrmAtivacao_exemplo in 'uFrmAtivacao_exemplo.pas' {FrmAtivacao},
```

Copie `libsodium.dll` (32 ou 64 bits, conforme o `.exe`) para a pasta
do executável.

---

## 1) Função `verificahardkey` (linha ~4298)

**Localizar** (função inteira, de `function tform1.verificahardkey`
até o `end;` que a fecha, por volta da linha 4423):

```pascal
function tform1.verificahardkey: boolean;
var
retcode ,handle,block_index:integer;
uid,hid:cardinal;
buffer :array [0..512]of char;
str:string;
pchars :PChar;
begin
//  rockey2
    hardkeyautomacao:=false;
    ...
    RY2_Close(handle);
end;
```

**Substituir pelo corpo inteiro** por:

```pascal
function tform1.verificahardkey: boolean;
var
  r: TResultadoLicenca;
begin
  // TS5 sem hardkey: fonte unica e a licenca web (uAtivacao).
  result := false;
  licensa := false;
  tplicensaint := 0;
  hardkeyautomacao := false;

  r := Licenciamento.VerificarLicenca;

  if not r.Valida then
  begin
    tplicensa := 'Sem licenca';
    exit;
  end;

  licensa := true;
  result := true;

  // nivel web = tplicensaint direto: light=1, basico=2, cameras=3,
  // eixos=4, extreme=5 (schema ja alinhado com o painel de licencas)
  tplicensaint := r.Nivel;

  // automacao de cameras/semaforo exige nivel 3 (cameras) ou mais
  hardkeyautomacao := r.Nivel >= 3;

  case r.Nivel of
    1: tplicensa := 'Light';
    2: tplicensa := 'Basico';
    3: tplicensa := 'Automacao Cameras';
    4: tplicensa := 'Plus';
    5: tplicensa := 'Extreme';
  else
    tplicensa := r.Tier;
  end;
end;
```

**Nota sobre o tier "Light":** o painel web já emite esse nível (1),
mas ele não tinha equivalente no hardkey antigo. No código acima ele
libera `hardkeyautomacao := False` (igual ao antigo "Básico"). Se
"Light" deve liberar menos recursos que "Básico" hoje, avise que ajusto
os pontos que comparam `tplicensaint` por valor exato (são poucos, veja
a lista abaixo).

---

## 2) Bloco de inicialização (linha ~6778-6829)

**Localizar:**

```pascal
    FormSplash.Label4.Caption:='Antes do Verifica hardkey';
    ...
    liberadosistema:=True;
    try
    begin
        if  verificahardkey then
        begin
            emaildeuso(nil);
            ...
        end
        else
        begin
            tplicensa:='Demonstração';
            emaildedemonstracao(nil);
            ...
            frm_login.Label4.Visible:=true;
        end;
    end;
    except on e:exception do
    begin
        Application.MessageBox(PChar(e.Message),'Erro verifica hardkey',MB_ICONINFORMATION);
        frm_login.errosnovo('Erro verifica hardkey '+#13+e.Message);
        gravaerrotabelaemail('Erro verifica hardkey '+#13+e.Message);
    end;
    end;
```

**Substituir o bloco `else` (modo Demonstração) e o `except` por
bloqueio total:**

```pascal
    FormSplash.Label4.Caption:='Antes do Verifica hardkey';
    FormSplash.Label4.Visible:=true;
    FormSplash.Label4.update;
    liberadosistema:=True;
    try
    begin
        if  verificahardkey then
        begin
            emaildeuso(nil);
            if (dados.tabconfigEXPORTA_MYSQL.Value=0) then //envia fora
            begin
              ipexterno:=Fipexterno;
            end;
            namepc:=nomepc;
            Timer_conexao.Enabled:=true;

            frm_login.Color:=clwhite;
            frm_login.Label4.Visible:=false;
            frm_login.Panel3.Color:=$00C08000;
            frm_login.label13.Color:=$00C08000;
            frm_login.label3.Color:=$00C08000;
            frm_login.label23.Color:=$00C08000;
        end
        else
        begin
            // sem licenca web valida: tenta abrir a tela de ativacao;
            // se o operador nao ativar, bloqueia totalmente.
            FrmAtivacao := TFrmAtivacao.Create(Application);
            try
              if FrmAtivacao.ShowModal <> mrOk then
              begin
                Application.MessageBox(
                  PChar('Nenhuma licenca valida. O Total Scale 5 sera encerrado.'),
                  'Licenca necessaria', MB_ICONERROR);
                Application.Terminate;
                Exit;
              end;
            finally
              FrmAtivacao.Free;
              FrmAtivacao := nil;
            end;

            // reavalia apos a ativacao
            if not verificahardkey then
            begin
              Application.MessageBox(
                PChar('Licenca ainda invalida. O Total Scale 5 sera encerrado.'),
                'Licenca necessaria', MB_ICONERROR);
              Application.Terminate;
              Exit;
            end;

            emaildeuso(nil);
            ipexterno:=Fipexterno;
            namepc:=nomepc;
            Timer_conexao.Enabled:=true;
            frm_login.Color:=clwhite;
            frm_login.Label4.Visible:=false;
        end;
    end;
    except on e:exception do
    begin
        Application.MessageBox(PChar(e.Message),'Erro ao verificar licenca',MB_ICONINFORMATION);
        frm_login.errosnovo('Erro ao verificar licenca '+#13+e.Message);
        gravaerrotabelaemail('Erro ao verificar licenca '+#13+e.Message);
        Application.Terminate;
        Exit;
    end;
    end;
```

O `Exit` dentro do `try` encerra este procedimento de inicialização;
como `Application.Terminate` já foi chamado, o programa fecha assim que
a fila de mensagens for processada — não chega a abrir a tela
principal sem licença.

---

## 3) Timer de monitoramento (linha ~8400)

Hoje esse timer fica de olho no dongle físico. Ele vira a revalidação
online periódica (a cada 7 dias, com 7 dias de tolerância offline —
igual ao TS6).

**Localizar:**

```pascal
procedure TForm1.monitora_hardkeyTimer(Sender: TObject);
begin
try
begin
  if (tplicensaint <> 0) then
      if ((not verificahardkey) and (not flaghardkey)) then
      begin
              flaghardkey:=true;
              FormHardkey.showmodal;
              if fechahardkey then
              begin
                  if verificahardkey then
                  begin
                        flaghardkey:=false;
                        exit;
                  end
                  else
                  begin
                        Application.MessageBox(PChar('Falha na leitura da HardKey!!!'), 'Operação inválida', MB_ICONERROR);
                        agoraimprime:=0;
                        timersemaforo.Enabled:=false;
                        TIMERSAIDA.Enabled:=false;
                        ComPortnovo.Connected:=false;
                        Freelibrary(hRY2);
                        Application.Terminate;
                  end;
              end;
      end;
end;
except
  ...
end;
end;
```

**Substituir por:**

```pascal
procedure TForm1.monitora_hardkeyTimer(Sender: TObject);
var
  msg: string;
begin
  try
    case RevalidarSeNecessario(msg) of
      rvBloqueio:
        begin
          Application.MessageBox(PChar(msg), 'Licenca bloqueada', MB_ICONERROR);
          agoraimprime:=0;
          timersemaforo.Enabled:=false;
          TIMERSAIDA.Enabled:=false;
          ComPortnovo.Connected:=false;
          Application.Terminate;
        end;
      rvSemRede:
        begin
          // dentro do prazo extra sem internet; nao bloqueia ainda
        end;
      rvOK:
        ; // nada a fazer
    end;
  except
    on e: exception do
      frm_login.errosnovo('Erro na revalidacao de licenca '+#13+e.Message);
  end;
end;
```

O `monitora_hardkey: TTimer` (linha ~116 do `.dfm`/`interface`) pode
manter o intervalo atual ou ser ajustado — a lógica de "só revalida a
cada 7 dias" já está dentro de `RevalidarSeNecessario`, então mesmo
chamando o timer com frequência (ex.: a cada hora) ele só bate no
servidor quando o ciclo vence.

---

## 4) Ping de uso (monitoramento pelo painel)

**O servidor já está pronto — não precisa instalar nada lá.** O
`api/ping.php` e a tela `painel/maquinas.php` já existem em produção,
com as tabelas `maquinas` e `acessos` já criadas, e a origem
`'licenca'` já é aceita. O `uPing.pas` foi feito para conversar com
essa API como ela é hoje.

No Delphi: adicione `uPing` ao `uses` do `Unit1.pas` e do `fili1.dpr`.

No bloco de inicialização (mesmo trecho do item 2), logo após
confirmar que `verificahardkey` retornou `True` (tanto no caminho
direto quanto no caminho pós-ativação), adicione a chamada:

```pascal
IniciarMonitoramento;
```

Não chame antes disso — sem licença confirmada não há `chave` válida
para identificar a máquina no painel.

### Como o TS5 vai aparecer no painel

O `ping.php` só conta `tipo='abertura'` no contador de aberturas;
`presenca` (a cada 15 min) e `fechamento` apenas atualizam o último
acesso. Um PC ligado o dia todo não infla a estatística.

Como o TS5 não usa mais dongle, todas as máquinas dele chegam com
`origem='licenca'`. No painel, elas somem do contador "Ainda no
dongle" — que é exatamente o indicador de progresso da migração.

---

## Pontos que comparam `tplicensaint` por valor exato

Só há um lugar que usa o valor numérico para outra coisa além de
`<> 0` — o desenho do ícone na status bar (`ImageListe.Draw(...,
tplicensaint)`, linha ~8767). Como o schema web vai de 1 a 5 (antes o
hardkey ia de 0 a 4), esse índice desloca em +1. Verifique se a
`ImageList` correspondente tem um ícone na posição 5 (índice do
Extreme); se não tiver, adicione ou ajuste o `case` ali para mapear de
volta.

Os demais (`if tplicensaint = 0`, `if tplicensaint <> 0`, `if
hardkeyautomacao then`, `if licensa then`) continuam funcionando sem
mudança, porque nunca dependeram do valor exato — só de zero/diferente
de zero ou do booleano.

## Arquivos removíveis (não mais necessários)

Após confirmar que tudo funciona, os seguintes podem ser retirados do
projeto: `F_hardkey.pas`/`.dfm`, `F_hardkey2.pas`/`.dfm` (telas do
dongle) e a chamada `Rockey2.dll`/`hRY2` associada. Sugiro manter por
uma versão de transição antes de remover, só por segurança.
