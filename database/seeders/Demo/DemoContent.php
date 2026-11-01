<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;

final class DemoContent
{
    /** @return list<array{owner: string|null, category: string|null, title: string, body: string}> */
    public static function savedReplies(): array
    {
        return [
            [
                'owner' => null,
                'category' => null,
                'title' => 'Greeting',
                'body' => "Hi {customer_first_name},\n\nThanks for contacting support. I am {agent_first_name} and I will be looking after ticket {ticket_number}. I will get back to you as soon as I have an update.\n\nBest regards,\n{agent_name}",
            ],
            [
                'owner' => null,
                'category' => 'account-access',
                'title' => 'Password reset steps',
                'body' => "Hi {customer_first_name},\n\nHere is how to reset your password:\n\n1. Open the sign-in page and click \"Forgot password\".\n2. Enter the email address you use to sign in.\n3. Open the link in the email within 60 minutes and choose a new password.\n\nIf the email does not arrive within 10 minutes, please check your spam folder and reply to this ticket ({ticket_number}).\n\n{agent_first_name}",
            ],
            [
                'owner' => null,
                'category' => 'billing',
                'title' => 'Refund policy',
                'body' => "Hi {customer_first_name},\n\nMonthly plans can be refunded within 14 days of the charge, and annual plans are refunded pro rata for the unused months. Refunds go back to the original payment method and usually appear within 5 to 7 business days.\n\nI have noted the request on ticket {ticket_number} and will confirm once it is processed.\n\n{agent_name}",
            ],
            [
                'owner' => null,
                'category' => 'technical-support',
                'title' => 'Escalation acknowledgement',
                'body' => "Hi {customer_first_name},\n\nI have escalated ticket {ticket_number} (\"{ticket_subject}\") to our engineering team. They have all the details you sent, so there is no need to repeat anything. I will update you as soon as I hear back, and at least once a day until it is fixed.\n\n{agent_first_name}",
            ],
            [
                'owner' => null,
                'category' => null,
                'title' => 'Closing message',
                'body' => "Hi {customer_first_name},\n\nI am glad we could sort this out. I am marking {ticket_number} as resolved. If anything comes up again, just reply to this ticket and it will reopen automatically.\n\nHave a great day,\n{agent_first_name}",
            ],
            [
                'owner' => null,
                'category' => null,
                'title' => 'Request for more information',
                'body' => "Hi {customer_first_name},\n\nTo look into this further, could you send us:\n\n- what you were doing when the problem happened,\n- a screenshot of any error message,\n- your browser and operating system,\n- roughly when it last happened.\n\nI will pause the ticket until we hear back from you.\n\n{agent_first_name}",
            ],
            [
                'owner' => 'a1',
                'category' => 'billing',
                'title' => 'Refund follow-up',
                'body' => "Hi {customer_first_name},\n\nJust checking in on {ticket_number}: has the refund appeared on your statement yet? If not, I will chase it with our payments team today.\n\n{agent_first_name}",
            ],
            [
                'owner' => 'a1',
                'category' => 'technical-support',
                'title' => 'Large export workaround',
                'body' => "Hi {customer_first_name},\n\nWhile we work on {ticket_number}, you can export large projects in smaller batches by filtering on status first, or use the API export, which has no time limit.\n\n{agent_first_name}",
            ],
            [
                'owner' => 'a2',
                'category' => 'technical-support',
                'title' => 'How to capture the browser console',
                'body' => "Hi {customer_first_name},\n\nCould you capture the browser console for us?\n\n1. Press F12 (or Cmd+Option+I on a Mac).\n2. Open the Console tab and reload the page.\n3. Take a screenshot of any red errors and attach it to this ticket.\n\nThanks!\n{agent_first_name}",
            ],
        ];
    }

    /** @return list<array{date: string, name: string}> */
    public static function holidays(CarbonImmutable $today): array
    {
        $upcoming = [
            ['12-16', 'Victory Day'],
            ['12-25', 'Christmas Day'],
            ['01-01', 'New Year\'s Day'],
            ['02-21', 'International Mother Language Day'],
        ];

        $holidays = [];

        foreach ($upcoming as [$monthDay, $name]) {
            $date = CarbonImmutable::parse($today->year.'-'.$monthDay, $today->timezone);
            $date = $date->lessThanOrEqualTo($today) ? $date->addYear() : $date;
            $holidays[] = ['date' => $date->format('Y-m-d'), 'name' => $name];
        }

        usort($holidays, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $holidays;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: int}> */
    public static function importRows(): array
    {
        return [
            ['LEG-1042', 'Invoice PDF shows the wrong currency', 'Our invoices from the old helpdesk show USD, but we are billed in EUR.', 'medium', 'billing', 'customer3@example.com', 'resolved', 26],
            ['LEG-1043', 'Cannot invite guests to a project', 'The invite button is greyed out for guest users on our plan.', 'low', 'account-access', 'customer6@example.com', 'open', 12],
            ['LEG-1044', 'Mobile app logs me out every day', 'I have to sign in again every morning on my phone.', 'high', 'technical-support', 'customer9@example.com', 'in_progress', 9],
            ['LEG-1045', 'Request for a data processing agreement', 'Our legal team needs a signed DPA before the renewal.', 'low', 'general-inquiry', 'customer12@example.com', 'closed', 20],
            ['LEG-1046', 'Gantt view for project timelines', 'A Gantt chart would help us plan our launches.', 'low', 'feature-requests', 'customer2@example.com', 'waiting_for_customer', 6],
            ['LEG-1047', 'Login page loops back to sign-in', 'After entering my password I land on the sign-in page again.', 'high', 'account-access', 'maria.lopez@@example', 'open', 4],
            ['', 'Weekly report email is empty', 'The weekly summary email arrives without any content.', 'medium', 'technical-support', 'customer4@example.com', 'open', 5],
            ['', 'Weekly report email is empty', 'The weekly summary email arrives without any content.', 'medium', 'technical-support', 'customer4@example.com', 'open', 5],
            ['LEG-1049', 'Cancel our second workspace', 'We no longer need the sandbox workspace.', 'low', 'billing', 'former.client@example.com', 'open', 3],
        ];
    }
}
