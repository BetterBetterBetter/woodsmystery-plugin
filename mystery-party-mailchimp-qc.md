# Mystery Party Mailchimp QC

Use this checklist every time a new Mystery Party is added or an existing party ticket is changed.

## Setup Check

1. In WordPress admin, open Woods Mystery > Mystery Mailchimp.
2. Confirm the party product appears in Configured Party Products.
3. Confirm the Audience ID matches the Mailchimp audience for that party.
4. Confirm the Mailchimp Audience column shows the expected audience name.
5. Confirm the Customer Journey column shows a sending journey for that audience.

If the audience is missing or the journey is not sending, stop and have the Mailchimp owner create or fix the audience/journey before testing checkout.

The same screen is also available from WooCommerce > Mystery Mailchimp for admins already working in WooCommerce.

## Single Ticket Test

1. Use a unique test email address that has not been used in this audience before.
2. Buy or manually create a completed Single ticket order for the party.
3. Open the WooCommerce order.
4. Confirm the order notes include "Mystery Mailchimp sync completed".
5. Use the N8N verifier to confirm the test email is in the correct Mailchimp audience.

## Couple Ticket Test

1. Use two different unique test email addresses.
2. Add a Couple ticket to checkout.
3. Confirm checkout requires Other Attendee First Name, Last Name, and Email.
4. Confirm checkout rejects using the billing email as the other attendee email.
5. Complete the order.
6. Open the WooCommerce order.
7. Confirm the order notes include "Mystery Mailchimp sync completed".
8. Use the N8N verifier to confirm both emails are in the correct Mailchimp audience.

## Resync Test

If an order needs to be sent again:

1. Open the WooCommerce order.
2. In Order actions, choose Resync Mystery Mailchimp.
3. Click Update.
4. Confirm the order note changes to either a completed sync or a specific failure reason.
5. Re-check the email address in the N8N verifier.

## Failure Rules

The flow is not approved if any of these are true:

- The product has no Mailchimp Audience ID.
- The audience ID is not found in Mailchimp.
- There is no sending Customer Journey for the audience.
- A Single ticket email is not added to the expected audience.
- A Couple ticket does not require the second attendee fields.
- A Couple ticket allows the same email for both attendees.
- A Couple ticket does not add both attendee emails to the expected audience.
- The order note shows "Mystery Mailchimp sync failed".
