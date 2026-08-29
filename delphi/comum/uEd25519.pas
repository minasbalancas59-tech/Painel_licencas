unit uEd25519;

{ =====================================================================
  Verificacao de assinatura Ed25519 para o Total Scale.

  ABORDAGEM RECOMENDADA: libsodium.dll
  ---------------------------------------------------------------------
  Coloque libsodium.dll (32 ou 64 bits, conforme seu exe) na mesma
  pasta do executavel. E a MESMA biblioteca que o PHP usa no servidor,
  entao a compatibilidade e garantida.

  Baixe libsodium para Windows em:
    https://download.libsodium.org/libsodium/releases/
  (pasta libsodium-win32 ou libsodium-win64, arquivo libsodium.dll)

  Se preferir NAO depender de DLL, existe implementacao Pascal pura de
  Ed25519 (ex.: a unit Ed25519 do projeto DCPCrypt/ou ports de ref10).
  Nesse caso, substitua o corpo de Ed25519_Verify pela chamada da lib
  escolhida, mantendo a mesma assinatura de funcao.
  ===================================================================== }

interface

uses
  System.SysUtils;

{ Verifica assinatura detached Ed25519.
  AMsg       = bytes do JSON assinado
  ASignature = 64 bytes da assinatura
  APubKey    = 32 bytes da chave publica
  Retorna True se a assinatura confere. }
function Ed25519_Verify(const AMsg, ASignature, APubKey: TBytes): Boolean;

{ Converte string hex ("A1B2...") para bytes. }
function HexToBytes(const AHex: string): TBytes;

implementation

const
  SODIUM_DLL = 'libsodium.dll';

{ crypto_sign_verify_detached retorna 0 quando a assinatura e valida. }
function crypto_sign_verify_detached(
  sig: PByte; m: PByte; mlen: UInt64; pk: PByte): Integer;
  cdecl; external SODIUM_DLL;

function sodium_init: Integer; cdecl; external SODIUM_DLL;

var
  GSodiumIniciado: Boolean = False;

procedure GarantirSodium;
begin
  if not GSodiumIniciado then
  begin
    if sodium_init < 0 then
      raise Exception.Create('Falha ao inicializar libsodium.');
    GSodiumIniciado := True;
  end;
end;

function Ed25519_Verify(const AMsg, ASignature, APubKey: TBytes): Boolean;
begin
  Result := False;
  if (Length(ASignature) <> 64) or (Length(APubKey) <> 32) then Exit;

  GarantirSodium;

  Result := crypto_sign_verify_detached(
    @ASignature[0],
    @AMsg[0],
    Length(AMsg),
    @APubKey[0]) = 0;
end;

function HexToBytes(const AHex: string): TBytes;
var
  i, n: Integer;
  s: string;
begin
  s := Trim(AHex);
  n := Length(s) div 2;
  SetLength(Result, n);
  for i := 0 to n-1 do
    Result[i] := StrToInt('$' + Copy(s, i*2+1, 2));
end;

end.
