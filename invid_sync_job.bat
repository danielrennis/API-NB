@echo off
REM invid_sync_job.bat - Sincronizacion programada de catalogos (Air + Invid)
REM Ejecutar diariamente a las 07:30 AM en el Programador de Tareas de Windows

powershell.exe -ExecutionPolicy Bypass -File "C:\xampp\htdocs\Prueba\Proveedores\actualizar_precios.ps1"
