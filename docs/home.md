# Project documentation
This documentation is made to explain all the features of this project and how it work.
# Technologies
| Technology | Version |
|---|---|
| Symphony | 7.4.22 |
| PhP | 8.4.27 |
# Useful pages
- [first steps](#first-step)
- [Architecture](#architecture)

## Private dispatch imports

Photo descriptions and metadata are optional for individual and bulk uploads.

An authenticated administrator can use **Dispatch import** to select an existing
catalog or create one, then upload photos and optionally assign each photo to a
normal album. Newly created catalogs have a six-digit identifier and a separate
six-digit access code. A confirmation displays the identifier and access code
after a successful upload. Uploading again to an existing catalog generates a new
access code and invalidates its previous code and visitor sessions. Save the new
code securely and share it with the identifier. The code is stored as a password
hash and cannot be recovered later.

Visitors can open **Private dispatch** with those two codes. This grants access
only to that catalog for the current browser session; it does not log the visitor
in to the site. They can download individual photos or all photos as a ZIP and
select photos for publication. After confirmation, selected photos become public;
unselected photos keep their existing status. Five failed attempts lock the
catalog for 15 minutes. Use HTTPS and communicate the codes securely.

Dispatch photos are stored in `var/private/pictures`, outside `public`. Private
photos are served only to the administrator or a session authorized for their
catalog; published photos are served to everyone. In production,
`PRIVATE_UPLOADS_PATH` selects the persistent host directory mounted there; ensure
it is writable by the web server and included in backups. Apply database migrations
before deploying. Existing photos remain public.
