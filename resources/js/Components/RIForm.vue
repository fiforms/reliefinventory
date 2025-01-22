<template>
	<div class="ri_form_container">
	  <div v-if="!record" class="ri_datatable_container">
	    <h2 class="ri_datatable_head">{{ title }}</h2>
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
		<button @click="newRecord()" class="ri_defaultbutton">New Record</button>
	  </div>
	  <div v-else class="ri_dataform_container">
	    <h2 class="ri_dataform_head">
	  	  <img :src="editing ? '/img/edit-icon.webp' : '/img/edit-padlock-icon.webp'" style="width: 1.5em; float: right; cursor:pointer;" @click="toggleEdit()" />
	  	  {{ title }} - Details
	    </h2>
		<slot :editing="editing" :record="record" :templates="templates"></slot>
		<button v-if="editing" @click="saveRecord()" class="ri_defaultbutton">Save</button>
		<button v-if="editing" @click="cancelRecord()" class="ri_formbutton">Cancel Changes</button>
		<button v-if="editing" @click="deleteRecord()" class="ri_formbutton" style="float: right">Delete</button>
		<button v-if="!editing" @click="cancelRecord()" class="ri_defaultbutton">Back to Orders</button>
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
  },
  data() {
    return {
      records: [],
	  templates: [],
	  record: null,
	  editing: false,
    };
  },
  methods: {
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
		this.record = JSON.parse(JSON.stringify(this.records[recordIndex]));
	},
	saveRecord() {
	  if(this.record && this.record.id) {
		// Record exists, we should update using PUT
		axios.put(this.datasource + "/" + this.record.id, this.record)
		.then((response) => {
		  this.records = [];
		  this.cancelRecord();
		  this.fetchRecords();
		})
		.catch((error) => {
		  console.log(error);
		});
	  }
	  else {
	    // This is a new record, create it using POST
		axios.post(this.datasource, this.record)
		.then((response) => {
		  this.records = [];
		  this.cancelRecord();
		  this.fetchRecords();
		})
		.catch((error) => {
		  console.log(error);
		});
	  }
	},
	deleteRecord() {
		axios.delete(this.datasource + "/" + this.record.id)
		.then((response) => {
			this.records = [];
			this.cancelRecord();
			this.fetchRecords();
		})
		.catch((error) => {
		  console.log(error);
		});
	},
	cancelRecord() {
		this.editing = false;
		this.record = null; // Reset the model in the parent
	},
	toggleEdit() {
		this.editing = !this.editing;
	},
	newRecord() {
		this.record = JSON.parse(JSON.stringify(this.templates['_default']));
	    this.editing = true;
	}
  },
  created() {
    this.fetchRecords();
  }
}

</script>
  