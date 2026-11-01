<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TicketPriority;
use InvalidArgumentException;

final class DemoScenarios
{
    public const array GENERIC_AGENT_LINES = [
        'Thanks for the update, {first_name}. I am checking this with the team now and will come back to you shortly.',
        'Quick update: I have reproduced part of this on our side and I am working through the rest. No action needed from you yet.',
        'Thanks for your patience. I have added the details to our internal ticket so the engineers can see the full history.',
        'That is useful, thank you. One more thing that would help: roughly what time did you last see this happen?',
    ];

    public const array GENERIC_CUSTOMER_LINES = [
        'Thanks, that helps. I will keep an eye on it and let you know.',
        'Understood. Let me check with my colleague and get back to you.',
        'Just following up on this one, is there any news?',
        'Great, thank you for the quick reply.',
    ];

    public const array GENERIC_NOTES = [
        'Checked the account history; nothing unusual apart from what the customer described.',
        'Customer is on an annual contract renewing next quarter, so worth keeping them happy.',
    ];

    public const array THANKS_LINES = [
        'All good now, thanks {agent}! Happy for you to close this.',
        'Confirmed it works on our side. Thanks for sorting it out so quickly.',
        'That did the trick. Much appreciated.',
        'Perfect, thank you. You can close the ticket.',
    ];

    public const array REOPEN_LINES = [
        'Sorry to reopen this, but it happened again this morning. Same symptoms as before.',
        'I thought this was fixed, but the problem is back for two of my colleagues.',
        'Reopening: the fix worked for a day, then it went back to the old behaviour.',
    ];

    /** @var array<string, DemoScenario>|null */
    private static ?array $all = null;

    public static function get(string $key): DemoScenario
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown demo scenario [{$key}].");
    }

    /** @return array<string, DemoScenario> */
    public static function all(): array
    {
        return self::$all ??= [...self::billing(), ...self::technical(), ...self::access(), ...self::general(), ...self::features()];
    }

    /** @return array<string, DemoScenario> */
    private static function billing(): array
    {
        return [
            'double-charge' => new DemoScenario(
                category: 'billing',
                priority: TicketPriority::High,
                subjects: ['Charged twice for our {month} subscription', 'Duplicate charge on invoice {invoice}', 'Card billed two times this month'],
                description: "Hi,\n\nOur company card was charged twice for the {plan} plan this month ({amount} each, both on {date}). Our finance team flagged it this morning. Invoice {invoice} only shows one payment.\n\nCould you refund the duplicate charge?\n\nThanks,\n{first_name}\n{company}",
                agentLines: [
                    'Hi {first_name}, thanks for flagging this. I can see two authorisations on {date}. One of them should drop off automatically, but I am checking with our payments provider to be sure.',
                    'I have confirmed the second charge was captured, not just authorised. I have issued a refund of {amount}; it usually shows on the statement within 5 to 7 business days.',
                    'The refund reference is RF-{reference}. If your bank needs it, I can send a PDF confirmation as well.',
                ],
                customerLines: [
                    'Thanks. Our bank says both charges are settled, not pending. Statement attached.',
                    'Great, the refund reference would help our accountant. Please send it over.',
                    'Our finance team confirmed the refund arrived. Thanks for the help.',
                ],
                notes: [
                    'Payment provider shows a retry after a timeout at checkout, which created the second capture. Known issue, engineering ticket PAY-212.',
                    'Refund approved by finance. No need to escalate further.',
                ],
                question: 'Hi {first_name}, could you send a screenshot of the bank statement showing both charges, with card numbers hidden? Our payments provider needs it before we can refund.',
                resolution: 'The duplicate charge of {amount} has been refunded (reference RF-{reference}). I am marking this as resolved, but just reply here if it does not show up within a week.',
                fileNames: ['pdf' => 'bank-statement-{month}.pdf', 'png' => 'card-statement.png'],
            ),
            'refund-downgrade' => new DemoScenario(
                category: 'billing',
                priority: TicketPriority::Medium,
                subjects: ['Refund after downgrading from {plan}', 'Partial refund for unused {plan} months', 'Downgraded plan but still paying the old price'],
                description: "Hello,\n\nWe downgraded from {plan} to the Starter plan on {date} because our team got smaller, but the next invoice still shows the old price. Are we entitled to a prorated refund for the unused period?\n\nBest regards,\n{first_name} ({company})",
                agentLines: [
                    'Hi {first_name}, thanks for getting in touch. I can see the downgrade on {date}. The change applies from the next billing cycle, which is why the current invoice still shows {plan}.',
                    'I have checked with our billing team and we can credit the difference for the remaining days. That works out to {amount} as account credit or a refund to the card, whichever you prefer.',
                    'Done. The credit is applied and the updated invoice is now available under Settings, Billing.',
                ],
                customerLines: [
                    'I see. Our contract says changes apply immediately, though. Could you double check?',
                    'A refund to the card would be best, thanks.',
                    'Got the updated invoice. All clear.',
                ],
                notes: [
                    'Customer is right: their contract (signed before the policy change) says plan changes are immediate. Honour it.',
                ],
                question: 'Could you let me know which billing email the invoice was sent to? I want to make sure I am looking at the right workspace.',
                resolution: 'The prorated refund of {amount} is on its way to your card. Resolving this for now; feel free to reply if anything looks off on the next invoice.',
                fileNames: ['pdf' => 'invoice-{invoice}.pdf'],
            ),
            'vat-invoice' => new DemoScenario(
                category: 'billing',
                priority: TicketPriority::Low,
                subjects: ['Need our VAT number on invoices', 'Add tax ID to past invoices', 'Invoice is missing company VAT details'],
                description: "Hi team,\n\nOur accountant needs our VAT number ({reference}) and full company address on all invoices, including the last three. Is that something you can add?\n\n{first_name}\n{company}",
                agentLines: [
                    'Hi {first_name}, yes, we can do that. You can add the VAT number yourself under Settings, Billing, Tax details, and new invoices will include it automatically.',
                    'For the past three invoices I have regenerated them with the VAT number. They are attached to your billing page now.',
                ],
                customerLines: [
                    'Added it, thanks. Can you also update the old ones? The accountant needs them for the quarter.',
                    'Perfect, those look right.',
                ],
                notes: ['Regenerated invoices {invoice} and the two before it with the tax ID.'],
                question: 'Could you confirm the exact company name and address as they should appear on the invoice?',
                resolution: 'All invoices now show your VAT number and registered address. Marking this as resolved.',
                fileNames: ['pdf' => 'invoice-{invoice}-corrected.pdf'],
            ),
            'failed-payment' => new DemoScenario(
                category: 'billing',
                priority: TicketPriority::High,
                subjects: ['Payment failed and the workspace is read-only', 'Card declined, team locked out of editing', 'Workspace suspended after failed renewal'],
                description: "Our renewal payment failed on {date} and now the whole workspace is read-only. We updated the card straight away but it still says the account is past due. {count} people can't work right now.\n\nPlease help urgently.\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, sorry about this. I can see the new card on file. The retry runs automatically every few hours, but I have triggered it manually now.',
                    'The payment went through and the workspace is unlocked. It can take a minute or two for everyone to see edit access again.',
                ],
                customerLines: [
                    'Still read-only for me after refreshing.',
                    'Working now for everyone. Thanks for the quick turnaround.',
                ],
                notes: ['The retry job had been paused for this workspace after three declines. Unpaused it manually.'],
                question: 'Could you confirm the last four digits of the new card so I can make sure the retry uses it?',
                resolution: 'Payment is settled and edit access is restored for all {count} members. Resolving this ticket.',
                fileNames: ['png' => 'read-only-banner.png'],
            ),
            'seat-proration' => new DemoScenario(
                category: 'billing',
                priority: TicketPriority::Medium,
                subjects: ['Proration looks wrong after adding {seats} seats', 'Charged for a full month for new seats', 'Seat upgrade invoice higher than expected'],
                description: "We added {seats} seats on {date}, mid-cycle, but the invoice charges a full month for each of them. I expected it to be prorated. Can someone explain the calculation?\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, happy to explain. Seats are prorated by day, but the invoice groups the prorated amount and the next full month together, which makes it look like a double charge.',
                    'I have attached a breakdown of the calculation. The prorated part for {seats} seats is {amount}.',
                ],
                customerLines: [
                    'Thanks, the breakdown makes sense now. Could the invoice show this more clearly in the future?',
                ],
                notes: ['Fair point about the invoice layout. Passed the feedback to the billing product manager.'],
                question: 'Which invoice number are you looking at? We had two invoices generated that week.',
                resolution: 'The charge is correct and the breakdown is on file. I have also passed your feedback about the invoice layout to the billing team.',
                fileNames: ['csv' => 'seat-proration-breakdown.csv', 'pdf' => 'invoice-{invoice}.pdf'],
            ),
            'annual-switch' => new DemoScenario(
                category: 'billing',
                priority: TicketPriority::Low,
                subjects: ['Switching from monthly to annual billing', 'How do we move to yearly billing?', 'Annual plan discount question'],
                description: "We would like to switch to annual billing to get the discount. Do we lose the remaining days of the current month, and can we pay by bank transfer instead of card?\n\nThanks, {first_name} at {company}",
                agentLines: [
                    'Hi {first_name}, great to hear. When you switch, the unused days of this month are credited against the annual invoice, so nothing is lost.',
                    'Bank transfer is available for annual plans. I have sent a quote for {amount} to your billing email.',
                ],
                customerLines: ['Got the quote, our finance team will pay it this week.'],
                notes: ['Sent the annual quote. Follow up if no payment arrives within 14 days.'],
                question: 'How many seats should the annual quote include?',
                resolution: 'Your workspace is now on annual billing. Thanks for staying with us!',
                fileNames: ['pdf' => 'annual-quote-{reference}.pdf'],
            ),
            'receipt-email' => new DemoScenario(
                category: 'billing',
                priority: TicketPriority::Low,
                subjects: ['Receipts still go to a former employee', 'Change the billing contact email', 'Update who receives invoices'],
                description: "Our receipts are still sent to someone who left the company. Please send them to finance@{domain} instead. I am the workspace owner.\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, I have updated the billing contact to finance@{domain}. Future receipts will go there.',
                ],
                customerLines: ['Thanks! Could you resend last month\'s receipt to the new address?'],
                notes: ['Verified the requester is the workspace owner before changing the billing contact.'],
                question: 'For security, could you reply from the workspace owner\'s email address so I can verify the request?',
                resolution: 'Billing contact updated and last month\'s receipt resent. Resolving this.',
            ),
        ];
    }

    /** @return array<string, DemoScenario> */
    private static function technical(): array
    {
        return [
            'export-timeout' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::High,
                subjects: ['CSV export times out for large projects', 'Export stuck at 99% and then fails', 'Cannot export project with {count} tasks'],
                description: "When I export the \"Q4 Roadmap\" project to CSV, the progress bar reaches 99% and then I get \"Export failed, please try again\". Smaller projects work fine. This one has about {count} tasks.\n\nBrowser: {browser} on {os}.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, thanks for the detail. I can reproduce the timeout with a project of that size. Our engineers are looking at it.',
                    'While we fix it, you can export in batches using the filter by status, or use the API export which has no time limit.',
                    'A fix has been deployed: large exports now run in the background and you get an email with the download link when it is ready.',
                ],
                customerLines: [
                    'Batches work as a workaround, thanks, but we need the full export for an audit on Friday.',
                    'Just tried again and got the email with the file. All {count} rows are there.',
                ],
                notes: [
                    'Export worker hits the 60-second gateway timeout above ~8k rows. Engineering ticket EXP-87.',
                    'Fix for EXP-87 deployed to production this morning.',
                ],
                question: 'Could you send the browser console log from when the export fails? It helps us see which request times out.',
                resolution: 'Large exports now run in the background, so this should not happen again. Resolving the ticket, but reply here if any export fails.',
                fileNames: ['txt' => 'export-console-log.txt', 'csv' => 'export-partial.csv', 'png' => 'export-failed.png'],
            ),
            'dashboard-blank' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::Critical,
                subjects: ['Dashboard shows a blank page after login', 'White screen for the whole team since this morning', 'App does not load after the latest update'],
                description: "Since about 9am nobody on our team can use the app. After logging in we only see a blank white page. We tried {browser} and another browser, incognito mode and clearing the cache. Nothing helps.\n\nThis is blocking {count} people.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, I am sorry about this. We are treating it as urgent. Could you tell me if the page title in the browser tab shows "Dashboard" or nothing at all?',
                    'Thanks, the console log helped. One of your dashboard widgets references a deleted report, which crashes the page. I have removed the broken widget from the shared layout.',
                    'Engineering has shipped a fix so a broken widget can no longer take down the whole dashboard.',
                ],
                customerLines: [
                    'The tab says Dashboard. Screenshot of the console attached.',
                    'It loads again! Thank you so much.',
                ],
                notes: [
                    'Root cause: widget pointing at a deleted report throws during render. Bug DASH-311 filed with high priority.',
                    'Paged the on-call engineer as this affects a whole workspace.',
                ],
                question: 'Could you open the browser console (F12), reload the page and send us a screenshot of any red errors?',
                resolution: 'The dashboard loads again for your whole team and the underlying bug is fixed. Resolving this one.',
                fileNames: ['png' => 'blank-dashboard.png', 'txt' => 'browser-console.txt'],
            ),
            'calendar-sync' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::Medium,
                subjects: ['Calendar sync stopped updating', 'Due dates no longer show in our calendar', 'Calendar integration out of date since {date}'],
                description: "Task due dates used to appear in our shared calendar, but nothing new has shown up since {date}. Old events are still there. We did not change any settings.\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, the calendar connection uses a token that expires after 90 days of inactivity. It looks like yours expired on {date}.',
                    'Could you reconnect it under Settings, Integrations, Calendar? Existing events will stay, and new ones will sync within a few minutes.',
                ],
                customerLines: [
                    'Reconnected it. New tasks show up again, thanks!',
                ],
                notes: ['Token expiry should trigger an email warning. It did not for this workspace; reported as INT-45.'],
                question: 'Which calendar provider are you syncing with, and is it a personal or a shared calendar?',
                resolution: 'Sync is working again after reconnecting. I have also asked the team to send a warning before the connection expires.',
            ),
            'webhook-401' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::High,
                subjects: ['Webhooks returning 401 since this morning', 'Webhook deliveries failing with Unauthorized', 'Our webhook endpoint rejects your requests'],
                description: "Since around {time} today all webhook deliveries to our endpoint fail with 401. We have not changed anything on our side. The delivery log attached shows the failures.\n\n{first_name}, platform team at {company}",
                agentLines: [
                    'Hi {first_name}, thanks for the log. We rotated the webhook signing secrets for all workspaces this morning after a security review, and the email about it may have gone to spam.',
                    'You can copy the new secret from Settings, Developers, Webhooks. Failed deliveries are kept for 72 hours, and you can replay them from the same page.',
                ],
                customerLines: [
                    'Found the email in spam, sorry. Updated the secret and replayed the failed ones. All green now.',
                ],
                notes: ['Secret rotation announcement had a high spam rate. Raised with the comms team.'],
                question: 'Could you share the request ID from one of the failed deliveries? It is in the X-Request-Id header.',
                resolution: 'Webhooks are delivering again after updating the signing secret, and the failed deliveries were replayed.',
                fileNames: ['txt' => 'webhook-delivery-log.txt'],
            ),
            'mobile-crash' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::High,
                subjects: ['Mobile app crashes when opening attachments', 'App closes when I tap a PDF', 'Crash on {device} when viewing files'],
                description: "The mobile app closes immediately when I tap any PDF attachment on a task. Images open fine. I am on {device}, latest app version. It started after the last update.\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, thanks for reporting this. We have had a few similar reports from {device} users and the mobile team is investigating.',
                    'A fix is in version 4.12, which is rolling out in the app store today. Could you update and try again?',
                ],
                customerLines: [
                    'Still crashing on my side. Crash log attached in case it helps.',
                    'Updated to 4.12 and PDFs open fine now.',
                ],
                notes: ['Crash is in the PDF preview library on older OS versions. Mobile ticket MOB-140.'],
                question: 'Which OS version is your device on, and does it happen with every PDF or only large ones?',
                resolution: 'Version 4.12 fixes the crash when opening PDF attachments. Resolving this; please reply if it happens again.',
                fileNames: ['txt' => 'crash-report.txt', 'png' => 'crash-screen.png'],
            ),
            'slow-search' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::Medium,
                subjects: ['Search takes over 20 seconds', 'Global search is extremely slow', 'Searching tasks times out', 'Search results never finish loading'],
                description: "Searching anything in our workspace takes 20 to 30 seconds, sometimes it never returns. A month ago it was instant. We have about {count} tasks.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, thanks for letting us know. I can see slow search queries for your workspace in our monitoring.',
                    'Our engineers rebuilt the search index for your workspace overnight. Searches should be back under a second.',
                ],
                customerLines: [
                    'Much better today, thanks!',
                ],
                notes: ['Search index for this workspace was fragmented after a bulk import. Rebuilt it.'],
                question: 'Does the delay happen for every search term, or only for broad terms like single letters?',
                resolution: 'The search index has been rebuilt and searches are fast again.',
            ),
            'email-notifications' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::Medium,
                subjects: ['Not receiving email notifications', 'Notification emails stopped arriving', 'No emails when tasks are assigned to me'],
                description: "I no longer get any email notifications, not even when a task is assigned to me. Notifications are enabled in my settings and I checked spam. Colleagues still receive theirs.\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, I checked our mail logs. Emails to your address are bouncing with "mailbox full". Could you check with your IT team?',
                    'I have also removed your address from our suppression list, so emails will start flowing again once the mailbox accepts them.',
                ],
                customerLines: [
                    'IT cleared the mailbox, and I just got a notification. Thanks.',
                ],
                notes: ['Address was on the bounce suppression list after 5 hard bounces.'],
                question: 'Could you confirm the exact email address on your profile? Sometimes there is a typo or an old alias.',
                resolution: 'Notifications are arriving again. Resolving this ticket.',
            ),
            'api-rate-limit' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::Medium,
                subjects: ['API rate limit hit with only a few requests', 'Getting 429 errors from the API', 'Rate limiting seems wrong for our token'],
                description: "Our integration gets HTTP 429 after only about 20 requests per minute, but the docs say the limit is 120. Log excerpt attached.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, the limit is per token and per IP. Your log shows three services sharing one token, so they hit the limit together.',
                    'Creating a separate token per service will give each one its own limit. I have also raised your workspace limit to 240 requests per minute.',
                ],
                customerLines: [
                    'Makes sense. We split the tokens and the errors are gone.',
                ],
                notes: ['Raised the rate limit for this workspace as a goodwill gesture; they are on the Business plan.'],
                question: 'Could you send the response headers from a 429 response? The X-RateLimit headers tell us which limit was hit.',
                resolution: 'Rate limits are working as expected with separate tokens. Resolving.',
                fileNames: ['txt' => 'api-429-log.txt'],
            ),
            'import-encoding' => new DemoScenario(
                category: 'technical-support',
                priority: TicketPriority::Low,
                subjects: ['Imported contacts show garbled characters', 'Accents broken after CSV import', 'Special characters wrong in imported names'],
                description: "After importing our contacts CSV, names with accents look broken, for example \"JosÃ©\" instead of \"José\". I attached a sample of the file.\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, your file is saved in Windows-1252 encoding, and the importer expects UTF-8. That is why accents are broken.',
                    'If you save the file as "CSV UTF-8" in your spreadsheet app and import it again, the names will be correct. I can also clean up the existing records for you.',
                ],
                customerLines: [
                    'Yes please clean up the existing ones, there are about {count} of them.',
                    'They look right now. Thanks!',
                ],
                notes: ['Ran the encoding fix script on the affected contacts.'],
                question: 'Which program did you use to create the CSV file?',
                resolution: 'Existing contacts are fixed and future imports will be correct when saved as UTF-8.',
                fileNames: ['csv' => 'contacts-sample.csv'],
            ),
        ];
    }

    /** @return array<string, DemoScenario> */
    private static function access(): array
    {
        return [
            'password-reset' => new DemoScenario(
                category: 'account-access',
                priority: TicketPriority::High,
                subjects: ['Password reset email never arrives', 'Cannot reset my password', 'No reset link in my inbox'],
                description: "I forgot my password and requested a reset link four times, but the email never arrives. Not in spam either. I have a client presentation in two hours and need my files.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, sorry for the trouble. Your company mail server is rejecting our emails because of a strict spam filter.',
                    'I have sent a one-time reset link to the backup email on your profile instead. It is valid for 60 minutes.',
                ],
                customerLines: [
                    'Got it on my personal email and I am back in. Thank you!',
                ],
                notes: ['Their mail gateway blocks our sending domain. Suggested they allow-list it.'],
                question: 'Could you ask your IT team whether emails from our domain are being blocked by your mail filter?',
                resolution: 'You are back into your account. I recommend asking IT to allow-list our domain so future emails arrive.',
            ),
            'lost-2fa' => new DemoScenario(
                category: 'account-access',
                priority: TicketPriority::Critical,
                subjects: ['Locked out after losing my 2FA phone', 'Lost phone, cannot pass two-factor login', 'Need two-factor authentication reset'],
                description: "My phone was stolen yesterday and I cannot log in because of two-factor authentication. I do not have the backup codes. I am the only admin of our workspace, so the whole team is stuck.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, I am sorry to hear about your phone. For security we need to verify your identity before resetting two-factor authentication.',
                    'Thank you, the verification is complete. I have reset two-factor authentication on your account. You will be asked to set it up again on your next login.',
                ],
                customerLines: [
                    'I have sent the ID and the signed form.',
                    'I am back in and set up 2FA on my new phone. Saved the backup codes this time!',
                ],
                notes: [
                    'Identity verified via video call against the ID on file. Approved by the security lead.',
                    'Recommended adding a second admin to the workspace.',
                ],
                question: 'Please reply with a photo ID and the signed account recovery form (attached) so we can verify you are the account owner.',
                resolution: 'Two-factor authentication has been reset and you have access again. I would recommend adding a second admin to your workspace.',
                fileNames: ['pdf' => 'account-recovery-form.pdf'],
            ),
            'sso-setup' => new DemoScenario(
                category: 'account-access',
                priority: TicketPriority::Medium,
                subjects: ['Help setting up SAML single sign-on', 'SSO login fails with "invalid audience"', 'Configuring SSO for our team'],
                description: "We are setting up SAML SSO with our identity provider. The test login fails with \"Invalid audience\". Screenshot of our IdP settings attached.\n\n{first_name}, IT at {company}",
                agentLines: [
                    'Hi {first_name}, the audience in your IdP must match the Entity ID shown on our SSO settings page exactly, including the trailing slash.',
                    'Your test login succeeded in our logs. You can now enforce SSO for the workspace when you are ready.',
                ],
                customerLines: [
                    'The trailing slash was the problem. Test login works now.',
                ],
                notes: ['Common setup mistake. Docs page could be clearer about the trailing slash.'],
                question: 'Could you share the SAML response from a failed login (with any personal data removed)?',
                resolution: 'SSO is configured and working. Resolving this ticket.',
                fileNames: ['png' => 'idp-settings.png', 'txt' => 'saml-response.txt'],
            ),
            'transfer-ownership' => new DemoScenario(
                category: 'account-access',
                priority: TicketPriority::Medium,
                subjects: ['Transfer workspace ownership to a new admin', 'Our workspace owner left the company', 'Change the account owner'],
                description: "Our previous workspace owner has left the company and we cannot reach them. I need to become the owner so I can manage billing. I am currently an admin.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, we can transfer ownership, but because the current owner cannot approve it, we need a short letter on company letterhead confirming the change.',
                    'Thanks for the letter. Ownership has been transferred to you.',
                ],
                customerLines: [
                    'Letter attached, signed by our CEO.',
                    'I can see the billing settings now. Thanks.',
                ],
                notes: ['Letterhead and signature checked against the company website.'],
                question: 'Could you send a signed letter on company letterhead confirming the ownership change?',
                resolution: 'You are now the owner of the workspace. Resolving this.',
                fileNames: ['pdf' => 'ownership-transfer-letter.pdf'],
            ),
            'account-deletion' => new DemoScenario(
                category: 'account-access',
                priority: TicketPriority::Low,
                subjects: ['Delete my account and personal data', 'Data deletion request', 'Please remove my user profile'],
                description: "I no longer use the service. Please delete my account and all personal data you hold about me, as required by data protection law.\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, we have received your deletion request. Your account will be deleted within 30 days, and you will get a confirmation email when it is done.',
                ],
                customerLines: ['Thank you. Will my comments in the shared workspace also be removed?'],
                notes: ['Deletion request logged in the privacy register.'],
                question: 'Before we delete the account: would you like an export of your data first?',
                resolution: 'Your account and personal data have been deleted. Comments in shared workspaces now show as "Former user".',
            ),
        ];
    }

    /** @return array<string, DemoScenario> */
    private static function general(): array
    {
        return [
            'data-retention' => new DemoScenario(
                category: 'general-inquiry',
                priority: TicketPriority::Low,
                subjects: ['How long do you keep deleted projects?', 'Data retention for archived projects', 'Can deleted projects be restored?'],
                description: "One of our project managers deleted a project by mistake last week. How long do you keep deleted projects, and can we restore it?\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, deleted projects are kept for 30 days in the trash, which admins can open from Settings, Trash. After that they are permanently removed.',
                    'I can see the project in your trash. Restoring it brings back all tasks, comments and files.',
                ],
                customerLines: ['Found it and restored it. Great to know about the trash!'],
                notes: ['Customer might want longer retention; mention the Enterprise plan if it comes up again.'],
                question: 'Do you remember the name of the project, or roughly when it was deleted?',
                resolution: 'The project is restored. Resolving this ticket.',
            ),
            'security-questionnaire' => new DemoScenario(
                category: 'general-inquiry',
                priority: TicketPriority::Medium,
                subjects: ['Security questionnaire for our procurement team', 'Vendor security review documents', 'Need your security and compliance information'],
                description: "Our procurement team needs a completed security questionnaire before we can renew. It is attached. The deadline is {date}.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, thanks for sending this. I have forwarded the questionnaire to our security team. They usually reply within five business days.',
                    'The completed questionnaire is attached, together with our latest penetration test summary.',
                ],
                customerLines: [
                    'Thanks, our procurement team is happy with it.',
                ],
                notes: ['Security team asked for an NDA before sharing the full pen test report; the summary is fine without one.'],
                question: 'Does your team need the full penetration test report? It requires a signed NDA.',
                resolution: 'Questionnaire completed and sent. Good luck with the renewal!',
                fileNames: ['pdf' => 'security-questionnaire.pdf', 'csv' => 'questionnaire-answers.csv'],
            ),
            'nonprofit-discount' => new DemoScenario(
                category: 'general-inquiry',
                priority: TicketPriority::Low,
                subjects: ['Do you offer a nonprofit discount?', 'Pricing for charities', 'Discount for a registered nonprofit'],
                description: "We are a registered charity with about {seats} volunteers. Do you have nonprofit pricing?\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, yes, registered nonprofits get 50% off any plan. I just need a copy of your registration certificate.',
                    'Thanks, I have applied the nonprofit discount to your workspace from the next invoice.',
                ],
                customerLines: ['Certificate attached.'],
                notes: ['Registration number checked in the public charity register.'],
                question: 'Could you send a copy of your charity registration certificate?',
                resolution: 'The nonprofit discount is active on your workspace.',
                fileNames: ['pdf' => 'charity-registration.pdf'],
            ),
            'onboarding-call' => new DemoScenario(
                category: 'general-inquiry',
                priority: TicketPriority::Low,
                subjects: ['Can we book an onboarding session?', 'Training for our new team members', 'Request a product walkthrough'],
                description: "We have {seats} new people joining next month. Could someone run a one-hour onboarding session for them?\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, of course. Our onboarding sessions run on Tuesdays and Thursdays. Which day suits your team?',
                    'Booked for Thursday at 10:00. The calendar invite is on its way.',
                ],
                customerLines: ['Thursday would be great, ideally in the morning.'],
                notes: ['Added to the customer success calendar.'],
                question: 'How many people will join, and what time zone are they in?',
                resolution: 'The onboarding session is booked. See you on Thursday!',
            ),
            'outage-question' => new DemoScenario(
                category: 'general-inquiry',
                priority: TicketPriority::High,
                subjects: ['Was there an outage yesterday afternoon?', 'Service unavailable for about an hour', 'Downtime report request'],
                description: "Between about {time} and an hour later yesterday our team got errors on every page. Was there an outage? Our management wants an incident report.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, yes, we had a partial outage yesterday caused by a database failover. I am sorry for the disruption.',
                    'The incident report is attached. It explains the cause and the steps we are taking so it does not happen again.',
                ],
                customerLines: ['Thanks, the report is exactly what we needed.'],
                notes: ['Customer is eligible for SLA credit under their contract. Applied it.'],
                question: 'Which region is your workspace hosted in? The outage only affected one region.',
                resolution: 'Incident report shared and service credit applied to your next invoice.',
                fileNames: ['pdf' => 'incident-report.pdf'],
            ),
        ];
    }

    /** @return array<string, DemoScenario> */
    private static function features(): array
    {
        return [
            'dark-mode' => new DemoScenario(
                category: 'feature-requests',
                priority: TicketPriority::Low,
                subjects: ['Dark mode for the web app', 'Please add a dark theme', 'Feature request: dark mode'],
                description: "Our designers work late and would love a dark theme in the web app. The mobile app already has one.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, thanks for the suggestion. Dark mode for the web app is on our roadmap. I have added your vote to the request.',
                    'Good news: dark mode is now in beta. You can turn it on under Profile, Appearance.',
                ],
                customerLines: ['Love it, thanks!'],
                notes: ['{count} votes on this request so far.'],
                question: 'Would a setting that follows the operating system theme work for your team?',
                resolution: 'Dark mode is available in beta. Thanks for the feedback!',
                fileNames: ['png' => 'dark-mode-mockup.png'],
            ),
            'bulk-edit' => new DemoScenario(
                category: 'feature-requests',
                priority: TicketPriority::Medium,
                subjects: ['Bulk edit due dates on tasks', 'Change many tasks at once', 'Feature request: multi-select editing'],
                description: "When a project slips we have to move the due date of every task by hand. Could you add a way to select many tasks and shift their dates together?\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, I have passed this to our product team with your use case. Shifting dates for many tasks is a common request.',
                    'Bulk editing shipped this week: select tasks with shift-click and choose "Shift dates".',
                ],
                customerLines: ['That will save us hours, thank you.'],
                notes: ['Linked to product request PR-77.'],
                question: 'Would you need to shift dates by a fixed number of days, or set them all to the same date?',
                resolution: 'Bulk editing is live. Resolving this request.',
            ),
            'custom-fields-export' => new DemoScenario(
                category: 'feature-requests',
                priority: TicketPriority::Low,
                subjects: ['Include custom fields in exports', 'Export is missing our custom columns', 'Feature request: custom fields in CSV'],
                description: "Our exports do not include custom fields like \"Cost centre\" and \"Client code\". We need them for reporting. Sample of what we expected attached.\n\n{first_name}, {company}",
                agentLines: [
                    'Hi {first_name}, thanks for the sample. Custom fields are not part of the export yet, but they are available through the API in the meantime.',
                    'Custom fields are now included in CSV exports as extra columns.',
                ],
                customerLines: ['Great, the new export has everything we need.'],
                notes: ['Product team already planned this for the next release.'],
                question: 'How many custom fields does your workspace use?',
                resolution: 'Custom fields are now part of the CSV export.',
                fileNames: ['csv' => 'expected-export.csv'],
            ),
            'notification-digest' => new DemoScenario(
                category: 'feature-requests',
                priority: TicketPriority::Low,
                subjects: ['Daily digest instead of instant notifications', 'Too many notification emails', 'Feature request: summary email'],
                description: "Our team gets dozens of notification emails a day. A single daily digest would be much nicer.\n\n{first_name}",
                agentLines: [
                    'Hi {first_name}, thanks. You can already reduce emails under Profile, Notifications, but a daily digest is a great idea. I have added it to the request list.',
                ],
                customerLines: ['Thanks, we will try the settings in the meantime.'],
                notes: ['{count} other customers asked for a digest this quarter.'],
                question: 'What time of day would be best for a digest email?',
                resolution: 'Logged as a feature request. We will update you when the digest ships.',
            ),
        ];
    }
}
