<template>
	<div class="ri_subform_container">
	  <div class="ri_subtable_container">
	    <h3 class="ri_datasubtable_head">{{ title }}</h3>
	    <table border="1" class="ri_datatable">
	      <thead>
	        <tr>
	          <slot name="thead"></slot>
	          <th v-if="enabled" style="width: 3em;">&nbsp;</th> <!-- Needed above the edit icon --> 
	        </tr>
	      </thead>
	      <tbody>
	        <tr v-for="(record, index) in records" :key="record.id">
			  <slot v-if="index != currentEdit" name="tbody" :record="record" :index="index"></slot>
			  <slot v-else :record="record" :index="index"></slot>
			  <td v-if="enabled">
			    <img :src="index == currentEdit ? '/img/edit-icon.webp' : '/img/edit-padlock-icon.webp'" style="width: 1em; cursor:pointer;" @click="toggleEdit(index)" />
			  </td>
	        </tr>
	      </tbody>
	    </table>
	  </div>
	</div>
</template>

<script>

export default {
  props: {
	  title: {
	    type: String,
	    required: true,
	  },
	  records: {
	    type: Array,
	    required: true,
	  },
	  enabled: {
	    type: Boolean,
	    required: true,
	  },
  },
  data() {
    return {
      currentEdit: null,
    };
  },
  watch: {
    enabled(newValue) {
      if(!newValue) {
        this.currentEdit = null;
      }
    },
  },
  methods: {
    toggleEdit(recordIndex) {
      if(this.currentEdit == recordIndex) {
        this.currentEdit = null;
      }
      else {
        this.currentEdit = recordIndex;
      }
    }
  }
}

</script>