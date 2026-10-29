# Feature logic

What each feature does and the rules it follows, in plain language, for a reader who hasn't read the code. One section per feature. Each section describes only behaviour the code actually has, and holds no file, class or test references.

Each task's documenter replaces its own section's `Pending` line when the task is done. If a later task changes a feature's logic, that task's documenter updates the affected section in place and changes nothing else.

## Platform foundation (T00)
_Pending._

## Authentication (T00a)
Authentication is plain email-and-password sign-in with a server-side session. There are no extra security layers on top of it.

**Logging in**
- A user signs in with an email and a password, and can tick "remember me" to stay signed in beyond the browser session.
- Wrong credentials give one generic error on the email field. It does not reveal whether the email exists.
- Sign-in attempts are limited to 5 per minute from the same address. After that the user must wait before trying again.
- After a successful sign-in the session is renewed. The user goes back to the page they originally asked for, or to the home page. The home page sends guests to the login page and every signed-in user to the dashboard.

**Signed-in users and the guest pages**
- A signed-in user who opens the login or register page is sent to the home page instead.

**Logging out**
- Logging out ends the session, renews the form-protection (CSRF) token and returns the user to the login page. Only signed-in users can log out.

**Customer self-registration**
- Anyone can register with a name, an email and a password typed twice. The name and email may be up to 255 characters. The email must be entered in lower case: an address with capital letters is rejected, not converted.
- The email must be unique.
- The password rule depends on the environment. In production it needs at least 12 characters with upper and lower case letters, numbers and symbols, and it must not appear in known data breaches. Outside production the default minimum of 8 characters applies.
- The new account is signed in straight away and sent to the home page.
- Registration does not assign a role. Roles and permissions are granted separately, so a new account has no permissions until that happens.

**What is not included**
- No two-factor authentication, passkeys, email verification, password reset, password confirmation step or account settings pages.
- The colour scheme simply follows the operating system's light or dark preference. There is no appearance setting.

**API tokens**
- API clients get a token by posting an email, a password and a device name. The same generic error is returned for bad credentials.
- Token requests are limited to 5 per minute for each email and address combination.
- A verified email is not required to obtain a token. The token carries the user's current permissions.
- A client can revoke the token it is using.
- A client holding a token can fetch the details of the user the token belongs to.

## Data model (T01)
_Pending._

## SLA calculation (T02)
_Pending._

## Ticket numbering (T03)
_Pending._

## Authorization (T04)
_Pending._

## Ticket lifecycle (T05)
_Pending._

## Assignment (T06)
_Pending._

## Conversation and attachments (T07)
_Pending._

## Search and filtering (T08)
_Pending._

## SLA escalation (T09)
_Pending._

## Notifications (T10)
_Pending._

## CSV import (T11)
_Pending._

## Dashboard (T12)
_Pending._

## Administration (T13)
_Pending._

## Security and performance (T14)
_Pending._
