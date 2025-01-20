<template>
  <div class="ir-combo-box">
    <input
	  v-if="enabled"
      type="text"
      v-model="search"
      @focus="isOpen = true"
      @blur="handleBlur"
      @input="filterOptions"
      class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 ir-combo-box-input"
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
        {{ option.name }}
      </li>
    </ul>
	<span v-if="!enabled" class="ir_disabled_input">{{ this.search }}</span>
  </div>
</template>

<script>
export default {
  name: "ComboBox",
  props: {
    options: {
      type: Array,
      required: true,
    },
	enabled: {
		type: Boolean,
		required: false,
		default: true,
	},
    modelValue: {
      type: [String, Number],
      required: false,
    },
  },
  data() {
    return {
      search: "",
      isOpen: false,
      filteredOptions: [],
    };
  },
  watch: {
    options: {
      immediate: true,
      handler(newOptions) {
        this.filteredOptions = newOptions;
      },
    },
    modelValue: {
      immediate: true,
      handler(newValue) {
        const selectedOption = this.options.find(option => option.id === newValue);
        this.search = selectedOption ? selectedOption.name : "";
      },
    },
  },
  methods: {
    filterOptions() {
      this.filteredOptions = this.options.filter((option) =>
        option.name.toLowerCase().includes(this.search.toLowerCase())
      );
    },
    selectOption(option) {
      this.search = option.name;
      this.isOpen = false;
      this.$emit("update:modelValue", option.id);
    },
    handleBlur() {
      setTimeout(() => {
        this.isOpen = false;
      }, 200);
    },
  },
};
</script>


<style scoped>
.ir-combo-box {
  position: relative;
  width: 100%;
  max-width: 300px;
}

.ir-combo-box-input {
  width: 100%;
  padding: 8px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 14px;
}

.ir-combo-box-dropdown {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background-color: white;
  border: 1px solid #ccc;
  border-radius: 4px;
  max-height: 150px;
  overflow-y: auto;
  z-index: 1000;
}

.ir-combo-box-option {
  padding: 8px;
  cursor: pointer;
}

.ir-combo-box-option:hover {
  background-color: #f0f0f0;
}
</style>
