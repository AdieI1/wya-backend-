# WYA Backend API

Base URL: `http://YOUR_IP:8000/api`

## Auth

| Method | Endpoint | Body |
|--------|----------|------|
| POST | `/register` | `first_name`, `last_name`, `email`, `password`, `password_confirmation` |
| POST | `/login` | `email`, `password` |
| POST | `/logout` | Bearer token required |
| GET | `/user` | Bearer token |
| PUT | `/user` | `first_name`, `last_name`, `email`, optional `password` + `password_confirmation` |

All event routes require header: `Authorization: Bearer {token}`

## Events

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/events/feed?filter=all\|created\|joined` | Events page tabs |
| POST | `/events/join` | `{ "event_code": "ABC123" }` |
| GET | `/events/calendar?month=9&year=2026` | Calendar dots |
| GET | `/events` | Your created upcoming events |
| GET | `/events/history` | Created + cancelled |
| GET | `/events/{id}` | Includes `event_code`, `is_creator`, `is_joined` |
| POST | `/events` | Auto-generates `event_code` |
| PUT | `/events/{id}` | Creator only |
| PATCH | `/events/{id}/cancel` | Creator only |
