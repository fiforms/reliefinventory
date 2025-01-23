<!-- ComboBox Vue Componen -->

<!-- 
	This component accepts a double v-model binding:
	  keyValue: updates the primary key in the parent datastructure.
	  updates: copies the selected record object into the parent datastructure
	  so that all linked properties can be updated in real time based on the selected object
	  
	Object data is loaded from the JSON url specified in the optionsource attribute
	
	This implementation expects the primary key of the options list to be "id"
	It also expects a field	called "name" to populate the visible options and to be searchable
	
	This behavior may be modified in future versions  
 -->

<template>
  <div class="ri_formcontrol">
	  <div class="ir-combo-box">
	    <input
		  v-if="enabled"
	      type="text"
	      v-model="search"
	      @focus="isOpen = true"
	      @blur="handleBlur"
	      @input="filterOptions"
	      class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 ir-combo-box-input ri_forminput"
	      placeholder="Select an option"
	    />
	    <ul
	      v-if="enabled && isOpen && filteredOptions.length > 0"
	      class="ir-combo-box-dropdown"
	    >
	      <li
	        v-for="(option, index) in filteredOptions"
	        :key="option.id"
	        @mousedown="selectOption(option)"
	        class="ir-combo-box-option"
	      >
	        {{ option[display] }}
	      </li>
	    </ul>
		<span v-if="!enabled" class="ir_disabled_input">{{ this.search }}</span>
	  </div>
  </div>
</template>

<script>
export default {
  name: "ComboBox",
  props: {
	enabled: {
		type: Boolean,
		required: false,
		default: true,
	},
    keyValue: {
      type: [String, Number],
      required: false,
    },
	optionsource: {
	  type: String,
	  required: true,	
	},
	updates: {
		type: Object,
		required: false
	},
	display: {
		type: String,
		required: false,
		default: "name",
	},
  },
  data() {
    return {
      search: "",
      isOpen: false,
      filteredOptions: [],
	  options: [],
    };
  },
  watch: {
    options: {
      immediate: true,
      handler(newOptions) {
        this.filteredOptions = newOptions;
		this.showOptionTitle();
      },
    },
    keyValue: {
      immediate: true,
      handler(newValue) {
        const selectedOption = this.options.find(option => option.id === newValue);
        this.search = selectedOption ? selectedOption[this.display] : "";
		if(this.updates && selectedOption) {
			this.$emit("update:updates", selectedOption);
		}
      },
    },
  },
  methods: {
	fetchOptions() {
	  axios
		.get(this.optionsource)
		.then((response) => {
		  this.options = response.data.records;
		})
		.catch((error) => {
		  console.error("Error fetching options:", error);
		});
	},
	filterOptions() {
      this.filteredOptions = this.options.filter((option) =>
        option[this.display].toLowerCase().includes(this.search.toLowerCase())
      );
    },
    selectOption(option) {
      this.search = option[this.display];
      this.isOpen = false;
      this.$emit("update:keyValue", option.id);
	  this.$emit("update:updates", option)
    },
	showOptionTitle() {
		const selectedOption = this.options.find(option => option.id === this.keyValue);
		this.search = selectedOption ? selectedOption[this.display] : "";
	},
    handleBlur() {
      setTimeout(() => {
        this.isOpen = false;
      }, 200);
    },
  },
  created() {
    this.fetchOptions();
  }
};
</script>

