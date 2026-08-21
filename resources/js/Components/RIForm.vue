<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- RIForm.vue -->

<!--
  Component to create a data entry form bound to a JSON api

  This component expects attributes for "title" and the "datasource" JSON API url

  It has four slots: #thead, #tbody, #default, and #listactions, with bindings as
  described below:

  #listactions is optional and rendered between the title bar and the table — the
      place for a page to add its own search box, toggle, or dropdown filter. RIForm
      itself has no opinion on what the filter UI looks like; pair it with the
      `filter` prop (a `(record) => boolean` predicate) to actually narrow the list:

      <RIForm ... :filter="(r) => !onlyFlagged || r.flagged">
        <template #listactions>
          <label><input type="checkbox" v-model="onlyFlagged" /> Flagged only</label>
        </template>
        ...
      </RIForm>

      `filter` is re-evaluated reactively whenever the reactive state it closes over
      changes (Vue tracks dependencies through the computed property that calls it),
      so no manual refresh wiring is needed.

  #thead is nested inside a <tr></tr> element at the top of the data table, and should
      contain multiple <th> headings corresponding to the columns desires to be
      displayed, i.e.

      <template #thead>
          <th>Name<th>
          <th>Address</th>
          <th>City</th>
      </template>

  #tbody is repeated for each row in table view.  It binds to {record, index} and expects
      <td> elements for each field, like this:

      <template #tbody="{ record, index }">
          <td>{{ record.first_name }} {{ record.last_name }}</td>
          <td>{{ record.address }}</td>
          <td>{{ record.city }}</td>
      </template>

  #default slot contains the data entry form, with bound controls. This is a non-tabular
      format for more flexibility. It has three bound models { record, editing, templates }
      in order to pass data into the form controls.

      {record} is an object containing the reactive record data that's currently
      being edited.

      {templates} is a collection of objects with blank records and default values for
      creating new records.  templates._default contains the template for the main form
      while other named templates can reflect new records in a subform.

      {editing} is simply a global boolean flag to indicate whether the form is open for
      editing.

      <template #default="{ record, editing, templates }">
          <div class="ri_formtable">
            <div class="ri_fieldset">
              <div class="ri_fieldlabel">Address:</div>
              <TextInput
                  id="address"
                  v-model="record.address"
                  required
                  autofocus
                  :enabled="editing"
              />
            </div>
          </div>
      </template>

  #actions is optional and replaces the default Save/Cancel/Delete/Back-to-List
      button row, for pages that need a different action flow (e.g. a
      multi-step wizard). It's bound to { editing, record, confirmingDelete,
      save, cancel, delete, keepRecord }: `save(keepOpen)` behaves like the
      default Save button, except when keepOpen is true it leaves the form
      open on the saved record (refreshed with the server's response, e.g. to
      pick up a newly-assigned id) instead of returning to the list. `cancel`
      and `delete` are the same handlers the default bar uses; `keepRecord`
      backs out of a delete confirmation. Omit this slot to keep the default
      behavior — every existing RIForm page is unaffected.

  RIForm also emits `select` (with the record) when a list row is opened,
      `new` (with the new record — the template default, or the server's
      response if `precreate`) when "New Record" is clicked, and `saved`
      (with the server's record) after every successful save — useful for a
      parent that needs to
      reset its own local state (e.g. a wizard step) alongside RIForm's.

-->
<template>
  <div class="ri_form_container">
    <div v-if="!record" class="ri_datatable_container">
      <h2 class="ri_datatable_head">{{ title }}
        <button @click="newRecord()" class="ri_defaultbutton ri_floating">{{ newrecordcaption }}</button>
      </h2>
      <div v-if="$slots.listactions" class="ri_listactions">
        <slot name="listactions"></slot>
      </div>
      <p v-if="successMessage" class="ri_success">{{ successMessage }}</p>
      <table border="1" class="ri_datatable">
        <thead>
          <tr>
            <slot name="thead"></slot>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(record, index) in filteredRecords" :key="record.id" @click="selectRecord(record)">
            <slot name="tbody" :record="record" :index="index"></slot>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else class="ri_dataform_container">
      <h2 class="ri_dataform_head">
        <img :src="editing ? '/img/edit-icon.webp' : '/img/edit-padlock-icon.webp'" style="width: 1.5em; float: right; cursor:pointer;" @click="toggleEdit()" />
        {{ title }} - Details
      </h2>
      <slot :editing="editing" :record="record" :templates="templates"></slot>
      <p v-if="saveError" class="ri_error">{{ saveError }}</p>
      <slot
        name="actions"
        :editing="editing"
        :record="record"
        :confirmingDelete="confirmingDelete"
        :save="saveRecord"
        :cancel="cancelRecord"
        :delete="deleteRecord"
        :keepRecord="() => (confirmingDelete = false)"
      >
        <div class="ri_formactions">
          <button v-if="editing" @click="saveRecord()" class="ri_defaultbutton">Save</button>
          <button v-if="editing" @click="cancelRecord()" class="ri_formbutton">Cancel Changes</button>
          <button v-if="editing" @click="deleteRecord()" class="ri_deletebutton" :class="{ ri_confirming: confirmingDelete }">
            {{ confirmingDelete ? 'Confirm Delete — cannot be undone' : 'Delete' }}
          </button>
          <button v-if="editing && confirmingDelete" @click="confirmingDelete = false" class="ri_linkbutton">Keep Record</button>
          <button v-if="!editing" @click="cancelRecord()" class="ri_defaultbutton">Back to List</button>
        </div>
      </slot>
    </div>
  </div>
</template>

<script>
import axios from "axios";

export default {
  props: {
    title: {
      type: String,
      required: true,
    },
    datasource: {
      type: String,
      required: true,
    },
    precreate: {
      type: Boolean,
      default: false,
    },
    newrecordcaption: {
      type: String,
      default: "New Record",
    },
    // Optional (record) => boolean predicate narrowing the list view. Pair
    // with the #listactions slot for the filter UI itself — see the doc
    // comment at the top of this file.
    filter: {
      type: Function,
      default: null,
    },
  },
  emits: ['select', 'new', 'saved'],
  data() {
    return {
      records: [],
      templates: [],
      record: null,
      editing: false,
      saveError: null,
      successMessage: null,
      confirmingDelete: false,
    };
  },
  computed: {
    filteredRecords() {
      return this.filter ? this.records.filter(this.filter) : this.records;
    },
  },
  methods: {
    describeSaveError(error) {
      const errors = error.response?.data?.errors;
      if (errors) {
        return Object.values(errors).flat().join(' ');
      }
      return error.response?.data?.message || 'Could not save. Please try again.';
    },
    fetchRecords() {
      axios
        .get(this.datasource)
        .then((response) => {
          this.records = response.data.records;
          this.templates = response.data.templates;
        })
        .catch((error) => {
          console.error("Error fetching records:", error);
        });
    },
    selectRecord(record) {
      this.successMessage = null;
      this.record = JSON.parse(JSON.stringify(record));
      this.$emit('select', this.record);
    },
    // keepOpen leaves the form open on the saved record (refreshed from the
    // server response) instead of returning to the list — for a page
    // implementing its own multi-step flow via the #actions slot.
    saveRecord(keepOpen = false) {
      this.saveError = null;
      this.successMessage = null;
      this.confirmingDelete = false;
      const isUpdate = !!(this.record && this.record.id);
      const request = isUpdate
        ? axios.put(this.datasource + "/" + this.record.id, this.record)
        : axios.post(this.datasource, this.record);
      // Resolves true/false rather than rejecting, so a plain
      // `@click="saveRecord()"` (the default action bar) doesn't produce an
      // unhandled rejection — callers that care about success (e.g. a
      // wizard's #actions slot deciding whether to advance) can await it.
      return request
        .then((response) => {
          this.$emit('saved', response.data.record);
          if (keepOpen) {
            this.record = response.data.record;
          } else {
            this.records = [];
            this.cancelRecord();
            this.fetchRecords();
            this.successMessage = isUpdate ? 'Record saved successfully.' : 'Record created successfully.';
          }
          return true;
        })
        .catch((error) => {
          console.log(error);
          this.saveError = this.describeSaveError(error);
          return false;
        });
    },
    deleteRecord() {
      // Two-step inline confirm: first click arms the button, second deletes.
      if (!this.confirmingDelete) {
        this.confirmingDelete = true;
        return;
      }
      this.confirmingDelete = false;
      this.saveError = null;
      this.successMessage = null;
      axios.delete(this.datasource + "/" + this.record.id)
        .then((response) => {
          this.records = [];
          this.cancelRecord();
          this.fetchRecords();
          this.successMessage = 'Record deleted successfully.';
        })
        .catch((error) => {
          console.log(error);
          this.saveError = this.describeSaveError(error);
        });
    },
    cancelRecord() {
      this.editing = false;
      this.record = null; // Reset the model in the parent
      this.saveError = null;
      this.confirmingDelete = false;
    },
    toggleEdit() {
      this.editing = !this.editing;
      this.confirmingDelete = false;
    },
    newRecord() {
      this.successMessage = null;
      if (this.precreate) {
        axios.post(this.datasource, [])
          .then((response) => {
            this.record = response.data.record;
            this.editing = true;
            this.records = [];
            this.fetchRecords();
            this.$emit('new', this.record);
          });
      } else {
        this.record = JSON.parse(JSON.stringify(this.templates['_default']));
        this.editing = true;
        this.$emit('new', this.record);
      }
    },
  },
  created() {
    this.fetchRecords();
  },
};
</script>
