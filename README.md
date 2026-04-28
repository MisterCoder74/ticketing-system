# Ticketing System

## MVP Assessment
This documentation provides a comprehensive overview of the Minimum Viable Product (MVP) assessment for the Ticketing System, focusing on essential features that deliver core functionality while allowing for future enhancements. The MVP aims to validate the product concept and gather user feedback.

## Internal-Use Focus
The Ticketing System is designed primarily for internal use, streamlining the ticket management process within organizations. By focusing on internal stakeholders, the system caters to specific requirements that enhance productivity and support organizational workflows.

## Visual Coherence
To ensure visual coherence, the Ticketing System adopts a consistent design language across all interfaces. This includes uniform color schemes, typography, and layout structures that foster an intuitive user experience and minimize learning curves.

## Production Readiness
Before deployment, the Ticketing System must undergo rigorous testing to confirm that all features function as intended in a production environment. This includes:

- Stress testing to evaluate performance under high load
- Bug fixing based on user feedback from MVP testing
- Final validation of all integrations with existing systems
- Deployment Recommendations

For a successful deployment, follow these recommendations:

- Ensure all testing has been completed and documented.
- Validate that the infrastructure meets the required specifications for hosting the Ticketing System.
- Train users on the system to ensure smooth adoption and transition.

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
