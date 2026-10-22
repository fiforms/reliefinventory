@extends('reports.help.layout')

@section('title', 'Partner &amp; Donor Info Guide')

@section('content')
    <p class="intro">
        Keep the record of each partner, donor, supplier and contact. These are the people and organizations picked on Receiving and Order Entry.
    </p>

    <div class="section">
        <h2>Finding and adding people</h2>
        <p>
            Open Setup &rarr; People Entry
            (needs the Manage People permission). Each row of the list shows the name, any
            organization, phone, email, roles and whether they are a volunteer. Type in
            <strong>Search by name or organization...</strong> to narrow the list, click a row to open
            it, or use the new-record button at the top to add someone.
        </p>
        <p>
            Click <strong>Save</strong> to keep your changes or <strong>Cancel Changes</strong> to
            throw them away. If a save fails, the reason shows on the form (for example an email or
            badge code already used by someone else).
        </p>
    </div>

    <div class="section">
        <h2>Names and organizations</h2>
        <p>
            Fill in a <strong>First Name</strong> and <strong>Last Name</strong>, an
            <strong>Organization</strong>, or both &mdash; <strong>at least one of the three is
            required</strong>. A donation often arrives with only a company name, or with nothing
            known at all; for that, use the built-in Unknown Donor record rather than inventing a name.
        </p>
    </div>

    <div class="section">
        <h2>Organizations and their contacts</h2>
        <ol>
            <li>
                Create the organization's own record (for example &ldquo;Macedonia SDA Church&rdquo;)
                and check <strong>This record is the organization itself</strong>. It then appears
                as a <em>org</em> tag in the list.
            </li>
            <li>
                Create a record for each contact person. In <strong>Parent Organization</strong>,
                search for and pick the organization.
            </li>
            <li>
                A <strong>Contact Role</strong> field appears once a parent is chosen &mdash; type
                something like Primary, Delivery or Billing.
            </li>
        </ol>
        <p>
            Contacts show &ldquo;Parent: ...&rdquo; in the list. Only records marked as an
            organization can be picked as a parent, and a record can't be its own parent.
        </p>
    </div>

    <div class="section">
        <h2>Other details</h2>
        <ul>
            <li>
                <strong>Category</strong> &mdash; an open-ended tag for filtering (Donor, Supplier,
                Warehouse Contact...). Type a new one to add it. It isn't required and has nothing
                to do with permissions.
            </li>
            <li>
                <strong>Badge Code</strong> &mdash; the code from the person's physical badge, if they
                have one. Each code can belong to only one person.
            </li>
            <li><strong>Phone</strong> and <strong>Email</strong> &mdash; an email address can only be used on one record.</li>
            <li><strong>Volunteer</strong> &mdash; check if the person volunteers; it's separate from their role.</li>
            <li><strong>Comments</strong> &mdash; free notes.</li>
        </ul>
    </div>

    <div class="section">
        <h2>Address, city, state and county</h2>
        <p>
            Enter the <strong>ZIP Code</strong> first, then the <strong>Address</strong>. When you
            leave an address field, the system checks it online and fills in blank
            <strong>City</strong>, <strong>State</strong> and <strong>County</strong> for you.
            If its spelling of the address differs from yours, it shows both and lets you accept
            its version or keep yours. County is also used on reports, so it's worth checking.
        </p>
        <ul>
            <li>
                If an address has never been checked, a <strong>Verify Address</strong> button
                appears while editing. It's disabled, and says it can't verify, when the system
                is in offline mode.
            </li>
            <li>
                Nothing you type is overwritten silently: City, State and ZIP are only filled in
                where blank. You can always type them or pick the county yourself.
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>Roles and partner approval</h2>
        <p>
            The <strong>Roles</strong> box here offers only the party-tracking roles (such as
            Partner, Donor and Volunteer). Staff login access and individual
            permission changes are handled under Setup &rarr; User Administration, not on this page.
        </p>
        <ul>
            <li>
                You can only give a role to someone if you hold all of that role's permissions yourself.
                The same rule applies to editing anyone who already holds a permission you don't:
                their record opens read-only with the message &ldquo;You don't have permission to edit
                this record.&rdquo; Use <strong>Back to List</strong> to leave it.
            </li>
            <li>
                A person with the Partner role also gets a <strong>Partner Status</strong> line with
                buttons for the moves that make sense from the current status: <strong>Approve</strong>,
                <strong>Deny</strong>, <strong>Block</strong>, <strong>Reconsider</strong> and
                <strong>Unblock (Approve)</strong>. Add an optional note and click
                <strong>Confirm</strong>. Every change is logged underneath with who made it and when.
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>Deleting a record</h2>
        <p>
            Click <strong>Delete</strong>, then <strong>Confirm Delete &mdash; cannot be undone</strong>
            (or <strong>Keep Record</strong> to back out). The Delete button only appears for people with the separate Delete People permission, which most roles don't have.
            The built-in <strong>Unknown Donor</strong> record can never be deleted.
        </p>
    </div>
@endsection
