<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- ComboBox Vue Component -->

<!-- 
	This component accepts a double v-model binding:
	  keyValue: updates the primary key in the parent datastructure.
	  updates: copies the selected record object into the parent datastructure
	  so that all linked properties can be updated in real time based on the selected object
	  
	Object data is loaded from the JSON url specified in the optionsource="" attribute
	
	the enabled="" attribute should link to the {editing} model passed from RIForm
	
	Finally the display="" identifies the fiel in the data source which should be displayed 
	in the control, (default is "name")
	
	This implementation expects the primary key of the options list to be "id"
	
 
 -->

<template>
  <div class="ri_formcontrol">
	  <div class="ir-combo-box">
	    <input
		  v-if="enabled"
		  ref="searchInput"
	      type="text"
	      v-model="search"
	      @focus="isOpen = true"
	      @blur="handleBlur"
	      @input="filterOptions"
	      @keydown="handleKeydown"
	      class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 ir-combo-box-input ri_forminput"
	      name="ri-combo-search"
	      autocomplete="off"
	      autocorrect="off"
	      autocapitalize="off"
	      spellcheck="false"
	      data-lpignore="true"
	      data-1p-ignore
	      :placeholder="placeholder"
	    />
	    <ul
	      v-if="enabled && isOpen && filteredOptions.length > 0"
	      class="ir-combo-box-dropdown"
	    >
	      <li
	        v-for="(option, index) in filteredOptions"
	        :key="option.id"
	        @mousedown.prevent="selectOption(option)"
	        :class="['ir-combo-box-option', { ir_combo_box_highlighted: index === highlightedIndex }]"
	      >
	        {{ option[display] }}<span v-if="secondaryDisplay && option[secondaryDisplay]"> &mdash; {{ option[secondaryDisplay] }}</span>
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
	  required: false,	
	},
	options: {
	  type: Array,
	  required: false,
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
	secondaryDisplay: {
		type: String,
		required: false,
	},
	searchFields: {
		type: Array,
		required: false,
	},
	placeholder: {
		type: String,
		required: false,
		default: "Select an option",
	},
  },
  data() {
    return {
      search: "",
      isOpen: false,
      filteredOptions: [],
	  optionlist: [],
	  highlightedIndex: -1,
    };
  },
  watch: {
    optionlist: {
      immediate: true,
      handler(newOptions) {
        this.filteredOptions = newOptions;
		this.showOptionTitle();
      },
    },
    keyValue: {
      immediate: true,
      handler(newValue) {
        const selectedOption = this.optionlist.find(option => option.id === newValue);
        this.search = selectedOption ? selectedOption[this.display] : "";
		if(this.updates && selectedOption) {
			this.$emit("update:updates", selectedOption);
		}
      },
    },
  },
  methods: {
	fetchOptions() {
	  if(this.options) {
		  this.optionlist = this.options;
	  }
	  else {
		  axios
			.get(this.optionsource)
			.then((response) => {
			  this.optionlist = response.data.records;
			})
			.catch((error) => {
			  console.error("Error fetching options:", error);
			});
		}
	},
	filterOptions() {
      const fields = this.searchFields && this.searchFields.length ? this.searchFields : [this.display];
      const term = this.search.toLowerCase();
      this.filteredOptions = this.optionlist.filter((option) =>
        fields.some((field) => option[field] && String(option[field]).toLowerCase().includes(term))
      );
	  this.highlightedIndex = this.filteredOptions.length ? 0 : -1;
    },
    selectOption(option) {
      this.search = option[this.display];
      this.isOpen = false;
	  this.highlightedIndex = -1;
      this.$emit("update:keyValue", option.id);
	  this.$emit("update:updates", option);
	  this.$emit("selected", option);
    },
	focus() {
	  this.$nextTick(() => this.$refs.searchInput?.focus());
	},
	handleKeydown(event) {
	  if (!this.isOpen || this.filteredOptions.length === 0) return;
	  if (event.key === "ArrowDown") {
	    event.preventDefault();
	    this.highlightedIndex = (this.highlightedIndex + 1) % this.filteredOptions.length;
	  } else if (event.key === "ArrowUp") {
	    event.preventDefault();
	    this.highlightedIndex = (this.highlightedIndex - 1 + this.filteredOptions.length) % this.filteredOptions.length;
	  } else if (event.key === "Enter" || event.key === "Tab") {
	    const option = this.filteredOptions[this.highlightedIndex] ?? this.filteredOptions[0];
	    if (option) {
	      if (event.key === "Enter") event.preventDefault();
	      this.selectOption(option);
	    }
	  } else if (event.key === "Escape") {
	    this.isOpen = false;
	  }
	},
	showOptionTitle() {
		const selectedOption = this.optionlist.find(option => option.id === this.keyValue);
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

