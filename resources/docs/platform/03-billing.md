---
title: Plans, prices and billing
section: Money
order: 30
---
- **Plans & prices**: modules in each plan, monthly and yearly prices (naira by default), student allowance, selling points for the website, and whether it is public or highlighted. Price changes apply to new subscriptions and renewals.
- **Billing runs every morning (06:10)**: renewal invoices 14 days before the period ends; *past due* after the due date; suspension 14 days later. Institution administrators are notified at each step.
- **Paystack** payments count only after Paystack confirms them (on return and by signed webhook); the same payment is never counted twice. Set `PAYSTACK_SECRET_KEY`, and point the Paystack webhook at `/webhooks/paystack`.
- **Bank transfers**: record them on the institution's page with the bank reference once the money is on the statement. `PLATFORM_BANK_DETAILS` is shown to institutions on their billing page.
