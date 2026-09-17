# RBQBot Requirements & Business Logic

## 1. Role Permissions & Access Control

* **Entreprise:** Has full CRUD access over commercials, views client histories, and monitors employee performance statistics.
* **Admin:** Functions as an Enterprise user with additional privileges to create new Enterprises, view the blacklist, and unblock clients.
* **Super Admin:** Functions as an Admin user with the exclusive authority to create new Admins.

---

## 2. Commercial Calling & Reservation Workflow

* **Selecting Clients:** Commercials reserve a batch of clients (e.g., up to 50) and start calling them.
* **Case "Oui" (Yes):** The client remains reserved by that specific commercial until the commercial manually changes their status back to available.
* **Case "Non" (No):** 
  * The client becomes unavailable to everyone for 3 months.
  * After 3 months, the client becomes available to all commercials except those who have previously reserved them.
  * If every commercial in the system has already called the client and received a "No," the client is automatically transferred to the blacklist.
* **Case "Boîte Vocale" (Voicemail):** 
  * The client remains reserved by that commercial. 
  * The commercial must select a recall time and type (minute, hour, day, week, month) to trigger a reminder.
  * If 1 month passes in the "Boîte Vocale" state without resolution, the client becomes available to other commercials and is permanently blocked from the original commercial.
* **Case "Blacklist":** The client is immediately blocked and becomes unavailable to all commercials.
* **Case "Unblacklist":** When an Admin unblocks a client, the client becomes fully available again and all previous reservations are cleared.


yes and no and black list and boit vocal are client resavrtion and clation sataus when some one  od one ofthe same thme in the histoy page for all of them add note optional 