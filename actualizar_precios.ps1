# actualizar_precios.ps1
# 1) Renueva el PHPSESSID de air-intra
# 2) Llama a sync2.php para descargar el catalogo actualizado
# Programar en Tarea Programada de Windows para ejecutar diariamente

$ScriptDir = "C:\xampp\htdocs\Prueba\Proveedores"
$LogFile   = "$ScriptDir\actualizar_precios.log"
$SyncUrl   = "http://localhost:81/prueba/Proveedores/sync2.php?action=sync"

function Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -Path $LogFile -Value "[$ts] $msg"
    Write-Host "[$ts] $msg"
}

Log "=== Inicio actualizacion ==="

# Paso 1: renovar sesion
try {
    Log "Renovando PHPSESSID..."
    & powershell.exe -ExecutionPolicy Bypass -File "$ScriptDir\renovar_sesion.ps1"
    if ($LASTEXITCODE -ne 0) { throw "renovar_sesion.ps1 retorno error $LASTEXITCODE" }
    Log "PHPSESSID renovado OK"
} catch {
    Log "ERROR renovando sesion: $_"
    exit 1
}

# Paso 2: sync catalogo Air
try {
    Log "Descargando catalogo desde air-intra..."
    $resp = Invoke-RestMethod -Uri $SyncUrl -Method GET -TimeoutSec 180
    if ($resp.ok) {
        Log "Catalogo actualizado: $($resp.count) productos - $($resp.date)"
    } else {
        Log "ERROR sync: $($resp.error)"
    }
} catch {
    Log "ERROR llamando sync2.php: $_"
}

# Paso 3: sync Invid (Proveedor 149)
try {
    Log "Descargando catalogo Invid (Proveedor 149)..."
    $InvidSyncUrl = "http://localhost:81/prueba/Proveedores/invid_sync.php?action=sync"
    $respInvid = Invoke-RestMethod -Uri $InvidSyncUrl -Method GET -TimeoutSec 600
    if ($respInvid.ok) {
        Log "Invid actualizado OK: $($respInvid.total) total (stock/precios: $($respInvid.sp_actualizados), nuevos catalogo: $($respInvid.nuevos_catalogo))"
    } else {
        Log "ERROR sync Invid: $($respInvid.error)"
    }
} catch {
    Log "ERROR llamando invid_sync.php: $_"
}

Log "=== Fin actualizacion ==="

