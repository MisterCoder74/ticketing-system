# Ticketing System

PHP ticketing system with role-based access (Admin / Operator / User), JSON file storage, Bootstrap 5 UI.

## Structure
```
index.php                  <- Login + ticket list
pages/ticket_details.php   <- Ticket detail / comments / uploads
inc/auth.php               <- Session, login, role guards
inc/api.php                <- REST-like API (20 endpoints)
inc/helpers.php            <- Sanitization, file upload, logging
assets/css/style.css       <- Bootstrap 5 customisations
assets/js/app.js           <- Frontend controller (fetch, AJAX, UI)
data/                      <- JSON storage (users, tickets, comments, logs)
uploads/                   <- Uploaded images per ticket
```

## Test credentials
| Username | Password | Role |
|---|---|---|
| admin | Admin@2026! | Amministratore |
| operator1 | Operator@1 | Operatore |
| utente1 | Utente@1 | Utente standard |

## Requirements
- PHP 8.0+
- Write permission on data/ and uploads/
