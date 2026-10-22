@extends('reports.help.layout')

@section('title', 'Master Item List Guide')

@section('content')
    <p class="intro">
        The catalog of everything the warehouse counts. Sorting, ordering and filling all pick from this list, so keeping it tidy keeps everything else tidy.
    </p>

    <div class="section">
        <h2>Item types and SKU rows</h2>
        <p>
            The list has two levels. An <strong>item type</strong> is the thing the warehouse
            counts and orders (for example &ldquo;Newborn diapers&rdquo;); stock is pooled by item
            type. Under each item type are one or more <strong>SKU rows</strong> (shown in the
            <strong>Items</strong> table on the form) &mdash; the specific products and package
            sizes that count as that item type, such as a particular brand's barcode. Everyone
            who sorts a SKU row ends up adding to the same item type's total.
        </p>
        <p>
            You need both the Manage Items and Manage Item Types permissions to open
            Setup &rarr; Master Item List.
            Without both, the page won't open &mdash; ask an administrator.
        </p>
    </div>

    <div class="section">
        <h2>Finding an item</h2>
        <p>
            Type in the <strong>Search by ACS Item# or Item Type...</strong> box to narrow the
            list. Click a column heading (<strong>Category</strong>, <strong>ACS Item#</strong>,
            <strong>Item Type</strong>, <strong>Unit</strong>, <strong>Active</strong>) to sort by
            it; click again to reverse. A check mark in <strong>Active</strong> means the item type
            is in use. Click a row to open it.
        </p>
    </div>

    <div class="section">
        <h2>Adding or editing an item type</h2>
        <p>
            Click <strong>Add New Item</strong>, or open an existing row. The form has these fields:
        </p>
        <ul>
            <li><strong>Category</strong> &mdash; which group the item belongs to (pick from the list).</li>
            <li>
                <strong>Family</strong> and <strong>Variant (optional)</strong> &mdash; the item number
                (see below).
            </li>
            <li><strong>Item Type Desc</strong> &mdash; the name people will see everywhere (required).</li>
            <li>
                <strong>Default Ordering Unit</strong> &mdash; the unit quantities are counted in
                (for example each, case, lb). The totals in the Items table use this unit.
            </li>
            <li><strong>Active</strong> &mdash; uncheck to take an item type out of use without deleting it.</li>
        </ul>
        <p>
            Click <strong>Save</strong> when you're done. A new item type automatically gets a
            &ldquo;GENERIC ITEM&rdquo; SKU row so it can be sorted right away.
        </p>
    </div>

    <div class="section">
        <h2>Item numbers: family and variant</h2>
        <p>
            The <strong>ACS Item#</strong> is made of two parts that are stored separately. The
            <strong>Family</strong> is one to four digits (<code>42</code>
            is saved as <code>0042</code>). The <strong>Variant</strong>
            is one or two digits and tells apart versions within the family, such as sizes.
        </p>
        <ul>
            <li>
                Each family + variant pair can be used only once. If it is taken, you'll see
                &ldquo;That family/variant number is already in use.&rdquo;
            </li>
            <li>
                Leave Variant blank and it's saved as <code>00</code>, the
                standard item of that family. The number is then shown without the variant
                (for example <code>318</code>); other variants show as
                <code>318-90</code>.
            </li>
            <li>
                Most families are shown without their leading zero (<code>042</code>,
                not <code>0042</code>); families of 1000 and up show all four digits.
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>Items that have no number yet</h2>
        <p>
            When a sorter meets something not in the catalog, they can add it right from the
            Sorting page.
            An item type added that way has no family or variant and is put on
            <strong>sort hold</strong>, so it can be sorted but is held out of order forms until it
            gets a real number. Give it a Family here to number it. Note that this form has no
            field for sort hold itself, so saving doesn't lift the hold on its own &mdash; if a
            numbered item still doesn't appear on order forms, tell an administrator.
        </p>
    </div>

    <div class="section">
        <h2>SKU rows and UPC barcodes</h2>
        <p>
            The <strong>Items</strong> table inside the form lists the SKU rows. Click
            <strong>New Line</strong> to add a row. To change a row, click its pencil icon; while a
            row is open, its trash icon removes it. Each row has:
        </p>
        <ul>
            <li><strong>Plus Code</strong> &mdash; a short code (up to 4 characters) that tells rows apart.</li>
            <li><strong>Item Description</strong> &mdash; what this specific product is.</li>
            <li>
                <strong>Pk Unit</strong>, <strong>Size</strong> and <strong>Case Qty</strong> &mdash; the
                package (box, bag, case...), how many of the unit it holds, and, if it is a case,
                how many packages are in it. <strong>Total</strong> shows the result (case quantity
                times size).
            </li>
            <li>
                <strong>UPC</strong> &mdash; the manufacturer's barcode on that product. A UPC belongs to
                one row and can't be reused on another.
            </li>
        </ul>
        <p>
            A UPC on a SKU row is what lets a sorter scan the product on the Sorting page and have
            the right item type and description fill in. UPC-A, EAN-13 and GTIN-14 spellings of
            the same number are recognised as one. If a product has two different barcodes, make
            two SKU rows.
        </p>
        <p>
            Deleting a row removes it from the item type. It doesn't change stock already counted
            for the item type.
        </p>
    </div>

    <div class="section">
        <h2>Carton Barcodes</h2>
        <p>
            A carton (case of several packs) often has its own barcode, different from the
            single items inside. When a sorter scans a carton the system doesn't know, the
            Sorting page opens <strong>New Carton Barcode</strong> and asks which item is inside and
            how many (packs &times; items per pack). Once saved, scanning that carton again fills in
            the contained item and the quantity, so carton and single scans end up on the same line.
        </p>
        <p>
            Setup &rarr; Carton Barcodes
            lists every confirmed carton (also needs Manage Items). Use the
            <strong>Search</strong> box to find one by barcode, item or who added it. The table
            shows <strong>Carton barcode</strong>, <strong>Contains</strong>,
            <strong>Items per carton</strong> and <strong>Added</strong>.
        </p>
        <ul>
            <li>
                Cartons can't be added or edited here. To remove one, click <strong>Remove</strong>,
                then <strong>Confirm remove</strong>.
            </li>
            <li>
                Removing doesn't change any item or anything already sorted. The next scan of that
                carton asks again what's inside. To fix a wrong entry, remove it and scan the carton again.
            </li>
        </ul>
    </div>
@endsection
