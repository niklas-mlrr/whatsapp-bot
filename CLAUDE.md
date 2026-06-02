# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview
A full-stack WhatsApp bot dashboard. It consists of a Node.js receiver (WhatsApp client using Baileys), a Laravel backend for data storage and API, and a Vue 3 frontend for the dashboard.

## Architecture
- **Receiver (Node.js):** Acts as the WhatsApp client. Receives messages from WhatsApp and forwards them via HTTP POST webhooks to the Laravel backend.
- **Backend (Laravel):** Manages the database, provides REST APIs for the frontend, handles incoming webhooks from the receiver, and manages real-time events via Laravel Reverb/Soketi.
- **Frontend (Vue 3):** A TypeScript + Tailwind SPA that provides a dashboard to view and manage WhatsApp messages.

## Common Commands

### Backend (Laravel)
- **Install:** `cd backend && composer install`
- **Setup:** `php artisan key:generate && php artisan migrate`
- **Run (Dev):** `cd backend && php artisan serve`
- **Run (Full Dev Stack):** `cd backend && composer run dev` (Runs server, queue, logs, and vite concurrently)
- **Tests:** `cd backend && php artisan test`
- **Storage Link:** `php artisan storage:link`

### Frontend (Vue 3)
- **Install:** `cd frontend/vue-project && npm install`
- **Run (Dev):** `cd frontend/vue-project && npm run dev`

### Receiver (Node.js)
- **Install:** `cd receiver && npm install`
- **Run:** `cd receiver && npm start`

## Development Notes
- **Environment:** Secrets are managed via `.env` files in `backend/` and the `receiver/` directory. Use `generate-secrets.sh` for production secret generation.
- **Webhooks:** The receiver and backend must share a matching `WEBHOOK_SECRET` and `RECEIVER_API_KEY`.
- **Real-time:** The project uses WebSockets for live updates. Backend configuration is found in `backend/soketi.json` or Laravel Reverb settings.
- **Logging:** 
  - Backend: `backend/storage/logs/laravel.log`
  - Receiver: `logs/app.log` (if `LOG_TO_FILE=true`)
