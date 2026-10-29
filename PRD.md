Project Description: Support Ticket & SLA Management System
Develop a Support Ticket Management System using Laravel.
The application should allow users/customers to create support tickets and support agents to manage, respond to, assign, resolve, and track those tickets.
1. Ticket Management
Implement ticket management functionality including:
●     Create ticket
●     View ticket
●     Update ticket
●     Resolve/close ticket
●     View upcoming/active tickets
●     View resolved/closed tickets
Each ticket should contain appropriate information such as:
●     Unique Ticket ID
●     Subject
●     Description
●     Priority
●     Category
●     Status
●     Assigned Agent
●     Created By
●     Created Date
●     SLA/Due Time
Example statuses may include:
●     Open
●     In Progress
●     Waiting for Customer
●     Resolved
●     Closed
You may extend or modify the statuses if your design requires it.
2. Ticket ID Generation
Generate a unique ticket number using a predefined prefix format.
Example:
TKT-2026-000001
The implementation should properly handle uniqueness and future growth.
3. Roles and Permissions
The system should contain at least the following roles:
●     Administrator
●     Support Agent
●     Customer/User
Expected authorization behaviour includes:
●     Customers should only be able to access their permitted tickets.
●     Support agents should only perform actions they are authorized to perform.
●     Administrators should have appropriate administrative access.
●     Authorization must be enforced on the server side.
You are free to design the permission structure.
4. Ticket Assignment
Tickets should be assignable to support agents.
Implement:
●     Manual ticket assignment
●     Automatic ticket assignment
You may determine the strategy for automatic assignment.
For example, you may consider:
●     Agent workload
●     Round-robin assignment
●     Ticket category
●     Agent availability
●     Any other reasonable strategy
Please document your chosen approach.
5. Concurrent Ticket Assignment
Consider the following scenario:
Two support agents attempt to accept the same unassigned ticket at approximately the same time.
The system must ensure that the ticket is not incorrectly assigned to multiple agents.
You should design an appropriate solution and be prepared to explain your implementation.
6. Ticket Conversation
Users and support agents should be able to communicate through the ticket.
The system should support:
●     Customer replies
●     Agent replies
●     Internal agent notes
Internal notes must never be visible to customers.
7. Attachments
Users and agents should be able to attach files to tickets or replies.
Appropriate consideration should be given to:
●     File validation
●     File storage
●     File size/type restrictions
●     Secure file access
8. SLA Management
Tickets should have a resolution SLA based on their priority.
Example:
Priority
Resolution Time
Low
48 Hours
Medium
24 Hours
High
8 Hours
Critical
2 Hours

The application should identify tickets such as:
●     Within SLA
●     Approaching SLA breach
●     SLA breached
The SLA configuration should be designed so that changing SLA rules does not require unnecessary changes throughout the application.
9. Business Hours
SLA calculation should consider configurable business hours.
Example:
Business Days: Sunday–Thursday
 Business Hours: 9:00 AM–6:00 PM
For example:
If a Critical ticket with a 2-hour SLA is created on Thursday at 5:00 PM, simply adding two clock hours would not correctly calculate its SLA deadline.
You should design an appropriate approach for calculating the deadline.
The solution does not need to cover every possible enterprise calendar scenario, but your implementation and reasoning should be clear and maintainable.
10. SLA Escalation
The application should automatically identify and escalate tickets that are approaching or have exceeded their SLA.
An escalation may include:
●     Marking the ticket as escalated
●     Notifying appropriate users
●     Sending an email
●     Creating an escalation activity/history entry
You may determine the exact escalation mechanism.
11. Notifications
Send notifications for important ticket events.
Examples:
●     Ticket created
●     Ticket assigned
●     Agent replied
●     Customer replied
●     Ticket resolved
●     SLA approaching
●     SLA breached
Email notifications should be processed appropriately without unnecessarily blocking user requests.
12. Search and Filtering
Provide ticket search and filtering functionality.
Examples:
●     Ticket ID
●     Keyword
●     Status
●     Priority
●     Category
●     Assigned agent
●     Customer
●     Created date
●     SLA status
Pagination should be implemented.
13. CSV Import
Provide a way to import ticket data from CSV.
The import should be designed to reasonably support large files.
Consider:
●     Memory usage
●     Validation
●     Invalid rows
●     Duplicate data
●     Error reporting
●     Long-running processing
You may decide the appropriate Laravel implementation.
14. Dashboard
Create a simple but useful dashboard.
It may contain information such as:
●     Total open tickets
●     Unassigned tickets
●     Critical tickets
●     SLA breached tickets
●     Tickets approaching SLA breach
●     Resolved tickets
●     Tickets by priority
●     Tickets by agent
The dashboard does not need to contain complex visualizations.
We are more interested in usability, query quality, and implementation.
15. Activity / Audit History
Important ticket activities should be traceable.
Example:
10:01 AM – Ticket created by User A
 10:05 AM – Assigned to Agent B
 10:20 AM – Priority changed from Medium to High
 10:35 AM – Agent replied
 11:00 AM – Ticket escalated
 11:15 AM – Status changed from In Progress to Resolved
The activity history should provide enough information to understand what happened to a ticket.
16. Database and Performance Considerations
Design the database assuming that the application may eventually contain a large number of:
●     Tickets
●     Ticket replies
●     Attachments
●     Activity records
●     Notifications
We will evaluate areas such as:
●     Database structure
●     Relationships
●     Indexes
●     Query efficiency
●     Pagination
●     N+1 query handling
●     Eloquent usage
You are not required to generate millions of records.
17. Automated Testing
Include automated tests for areas you consider important.
We intentionally do not specify exactly which tests must be written.
Part of the evaluation is understanding which areas you consider critical enough to test.

Technical Expectations
You are free to determine the architecture of the application.
We do not require you to use specific design patterns or Laravel components simply for the sake of using them.
Use Laravel features where you believe they are appropriate.
Your technical decisions should be reasonable, maintainable, and explainable.
We may evaluate areas such as:
●     Validation
●     Eloquent relationships
●     Authorization
●     Business logic organization
●     Jobs and queues
●     Events/listeners
●     Notifications
●     Scheduler
●     Database transactions
●     Error handling
●     Storage
●     Configuration
●     Testing
●     Security
●     Performance
