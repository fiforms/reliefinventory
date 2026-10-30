<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

Keep the record of each partner, donor, supplier and contact. These are the people and organizations picked on
[Receiving](/help/receiving) and [Order Entry](/help/order-entry).

## Finding and adding people

Open [Setup → People Entry](/setup/people) (needs the Manage People permission). Each row of the list shows the name,
any organization, phone, email, roles and whether they are a volunteer. Type in **Search by name or organization...** to
narrow the list, click a row to open it, or use the new-record button at the top to add someone.

Click **Save** to keep your changes or **Cancel Changes** to throw them away. If a save fails, the reason shows on the
form (for example an email or badge code already used by someone else).

## Names and organizations

Fill in a **First Name** and **Last Name**, an **Organization**, or both — **at least one of the three is required**. A
donation often arrives with only a company name, or with nothing known at all; for that, use the built-in Unknown Donor
record rather than inventing a name.

## Organizations and their contacts

1. Create the organization's own record (for example “Macedonia SDA Church”) and check **This record is the organization
   itself**. It then appears as a *org* tag in the list.
2. Create a record for each contact person. In **Parent Organization**, search for and pick the organization.
3. A **Contact Role** field appears once a parent is chosen — type something like Primary, Delivery or Billing.

Contacts show “Parent: ...” in the list. Only records marked as an organization can be picked as a parent, and a record
can't be its own parent.

## Other details

- **Category** — an open-ended tag for filtering (Donor, Supplier, Warehouse Contact...). Type a new one to add it. It
  isn't required and has nothing to do with permissions.
- **Badge Code** — the code from the person's physical badge, if they have one. Each code can belong to only one person.
- **Phone** and **Email** — an email address can only be used on one record.
- **Volunteer** — check if the person volunteers; it's separate from their role.
- **Comments** — free notes.

## Address, city, state and county

Enter the **ZIP Code** first, then the **Address**. When you leave an address field, the system checks it online and
fills in blank **City**, **State** and **County** for you. If its spelling of the address differs from yours, it shows
both and lets you accept its version or keep yours. County is also used on reports, so it's worth checking.

- If an address has never been checked, a **Verify Address** button appears while editing. It's disabled, and says it
  can't verify, when the system is in offline mode.
- Nothing you type is overwritten silently: City, State and ZIP are only filled in where blank. You can always type them
  or pick the county yourself.

## Roles and partner approval

The **Roles** box here offers only the party-tracking roles (such as Partner, Donor and Volunteer). Staff login access
and individual permission changes are handled under Setup → User Administration, not on this page.

- You can only give a role to someone if you hold all of that role's permissions yourself. The same rule applies to
  editing anyone who already holds a permission you don't: their record opens read-only with the message “You don't have
  permission to edit this record.” Use **Back to List** to leave it.
- A person with the Partner role also gets a **Partner Status** line with buttons for the moves that make sense from the
  current status: **Approve**, **Deny**, **Block**, **Reconsider** and **Unblock (Approve)**. Add an optional note and
  click **Confirm**. Every change is logged underneath with who made it and when.

## Deleting a record

Click **Delete**, then **Confirm Delete — cannot be undone** (or **Keep Record** to back out). The Delete button only
appears for people with the separate Delete People permission, which most roles don't have. The built-in **Unknown
Donor** record can never be deleted.
