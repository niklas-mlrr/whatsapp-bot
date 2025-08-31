@echo off
TITLE Laravel Soketi Server
cd /d "%~dp0"
npx soketi start --config=soketi.json
