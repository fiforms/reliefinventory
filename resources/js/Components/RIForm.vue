<template>
	<div class="ri_form_container">
	  <div v-if="!record" class="ri_datatable_container">
	    <h2 class="ri_datatable_head">{{ title }}</h2>
	    <table border="1" class="ri_datatable">
	      <thead>
	        <tr>
	          <th v-for="field in tabularfields"> {{ field.title }}</th>
	        </tr>
	      </thead>
	      <tbody>
	        <tr v-for="(record, index) in records" :key="record.id" @click="selectRecord(index)">
	          <td v-for="field in tabularfields">
				<span v-if="field.calculation"> {{ field.calculation(record) }} </span>
				<span v-else > {{ record[field.name] }}  </span> 
				
			  </td>
	        </tr>
	      </tbody>
	    </table>
	  </div>
	  <div v-else class="ri_dataform_container">
	    <h2 class="ri_dataform_head">
	  	  <img :src="editing ? '/img/edit-icon.webp' : '/img/edit-padlock-icon.webp'" style="width: 1.5em; float: right; cursor:pointer;" @click="toggleEdit()" />
	  	  {{ title }} - Details
	    </h2>
		<slot></slot>
		<button v-if="editing" @click="saveRecord()" class="ri_defaultbutton">Save</button>
		<button v-if="editing" @click="cancelRecord()" class="ri_formbutton">Cancel Changes</button>
		<button v-if="!editing" @click="cancelRecord()" class="ri_defaultbutton">Back to Orders</button>
	  </div>
    </div>
</template>

<script>
import axios from "axios";

export default {
  props: {
	  tabularfields: {
	    type: Array,
	    required: true,
	  },
	  title: {
	    type: String,
	    required: true,
	  },
	  datasource: {
	    type: String,
	    required: true,
	  },
	  record: {
	    type: [Object, null],
	    required: true, // Ensure that the parent must provide this prop
	  },
	  editing: {
		type: Boolean,
		required: true,
	  },
  },
  data() {
    return {
      records: [],
    };
  },
  methods: {
    fetchRecords() {
      axios
        .get(this.datasource)
        .then((response) => {
          this.records = response.data;
        })
        .catch((error) => {
          console.error("Error fetching records:", error);
        });
    },
	selectRecord(recordIndex) {
		const selectedRecord = JSON.parse(JSON.stringify(this.records[recordIndex]));
		this.$emit("update:record", selectedRecord);
	},
	saveRecord() {
	  if(this.record && this.record.id) {
		// Record exists, we should update using PUT
		axios.put(this.datasource + "/" + this.record.id, this.record)
		.then((response) => {
		  const selectedIndex = this.records.findIndex((r) => {return r.id == this.record.id});
		  if(selectedIndex == -1) {
			console.log('There was a problem updating local data');
			return 1;
		  }
		  this.records[selectedIndex] = JSON.parse(JSON.stringify(this.record))
		  this.cancelRecord();
		})
		.catch((error) => {
		  console.log(error);
		});
	  }
	  else {
	    // This is a new record, create is using POST
		axios.post(this.datasource, this.record)
		.then((response) => {
		  this.records.append(JSON.parse(JSON.stringify(this.record)));
		  this.cancelRecord();
		  console.log(response);
		})
		.catch((error) => {
		  console.log(error);
		});
	  }
	},
	cancelRecord() {
		if(this.editing) {
			this.$emit("update:editing", false);
		}
		this.$emit("update:record", null); // Reset the model in the parent
	},
	toggleEdit() {
		this.$emit("update:editing", !this.editing);
	},
  },
  created() {
    this.fetchRecords();
  }
}

</script>
  