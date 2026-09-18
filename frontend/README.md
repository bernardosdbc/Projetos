# Frontend — Job Queue Console

React + Vite na pasta `frontend/`.

## Subir

Na raiz do projeto, a API precisa estar em `http://localhost:8010`.

```powershell
docker compose up -d app mysql worker-1 worker-2 worker-3
cd frontend
npm install
npm run dev
```

Abra **http://localhost:5173**. O Vite faz proxy de `/api` → `8010` (sem CORS).

## O que faz

- Contadores pending / processing / completed / dead
- Criar job (`send_email` + `fail_times` / `sleep_seconds` opcionais)
- Listar fila (filtro por status) com poll a cada 2s
- Detalhe do job + retry se `dead`
- Health da API

## Build

```powershell
npm run build
```
