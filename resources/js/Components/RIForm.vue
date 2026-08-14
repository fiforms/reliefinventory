<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- RIForm.vue -->

<!--
  Component to create a data entry form bound to a JSON api

  This component expects attributes for "title" and the "datasource" JSON API url

  It has three slots: #thead, #tbody, and #default, with bindings as described below:

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

-->
<template>
  <div class="ri_form_container">
    <div v-if="!record" class="ri_datatable_container">
      <h2 class="ri_datatable_head">{{ title }}
        <button @click="newRecord()" class="ri_defaultbutton ri_floating">{{ newrecordcaption }}</button>
      </h2>
      <p v-if="successMessage" class="ri_success">{{ successMessage }}</p>
      <table border="1" class="ri_datatable">
        <thead>
          <tr>
            <slot name="thead"></slot>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(record, index) in records" :key="record.id" @click="selectRecord(index)">
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
      <div class="ri_formactions">
        <button v-if="editing" @click="saveRecord()" class="ri_defaultbutton">Save</button>
        <button v-if="editing" @click="cancelRecord()" class="ri_formbutton">Cancel Changes</button>
        <button v-if="editing" @click="deleteRecord()" class="ri_deletebutton" :class="{ ri_confirming: confirmingDelete }">
          {{ confirmingDelete ? 'Confirm Delete — cannot be undone' : 'Delete' }}
        </button>
        <button v-if="editing && confirmingDelete" @click="confirmingDelete = false" class="ri_linkbutton">Keep Record</button>
        <button v-if="!editing" @click="cancelRecord()" class="ri_defaultbutton">Back to List</button>
      </div>
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
  },
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
    selectRecord(recordIndex) {
      this.successMessage = null;
      this.record = JSON.parse(JSON.stringify(this.records[recordIndex]));
    },
    saveRecord() {
      this.saveError = null;
      this.successMessage = null;
      this.confirmingDelete = false;
      if (this.record && this.record.id) {
        // Record exists, we should update using PUT
        axios.put(this.datasource + "/" + this.record.id, this.record)
          .then((response) => {
            this.records = [];
            this.cancelRecord();
            this.fetchRecords();
            this.successMessage = 'Record saved successfully.';
          })
          .catch((error) => {
            console.log(error);
            this.saveError = this.describeSaveError(error);
          });
      } else {
        // This is a new record, create it using POST
        axios.post(this.datasource, this.record)
          .then((response) => {
            this.records = [];
            this.cancelRecord();
            this.fetchRecords();
            this.successMessage = 'Record created successfully.';
          })
          .catch((error) => {
            console.log(error);
            this.saveError = this.describeSaveError(error);
          });
      }
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
          });
      } else {
        this.record = JSON.parse(JSON.stringify(this.templates['_default']));
        this.editing = true;
      }
    },
  },
  created() {
    this.fetchRecords();
  },
};
</script>
