<script setup lang="ts">
import { computed } from 'vue'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import type { Parameter } from '@/types/CodecConfig'
import type { AcceptableValue } from 'reka-ui'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Info } from 'lucide-vue-next'

const props = defineProps<{
  paramKey: string
  parameter: Parameter
  modelValue?: string | number | boolean | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | number | boolean | null]
}>()

const value = computed({
  get: () => props.modelValue ?? null,
  set: (val) => emit('update:modelValue', val)
})

const handleSelectChange = (newValue: AcceptableValue) => {
  if (typeof newValue == 'string') {
    value.value = newValue
  }
}

const handleSwitchChange = (checked: boolean) => {
  value.value = checked
}

const handleNumberInput = (val: string | number) => {
  const num = parseInt(String(val))
  value.value = isNaN(num) ? null : num
}

const handleTextInput = (val: string | number) => {
  value.value = val || null
}
</script>

<template>
  <div class="space-y-2">
    <div class="flex gap-x-3">
      <Label :for="paramKey" class="text-sm font-medium">
        {{ parameter.label }}
      </Label>
      <TooltipProvider v-if="parameter.help">
        <Tooltip>
          <TooltipTrigger>
            <Info :size="16" />
          </TooltipTrigger>
          <TooltipContent>
            <p>{{ parameter.help }}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    </div>

    <!-- Select input -->
    <Select v-if="parameter.inputType === 'select'" :model-value="String(value ?? '')"
      @update:model-value="handleSelectChange">
      <SelectTrigger :id="paramKey">
        <SelectValue :placeholder="`Select ${parameter.label}`" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="option in parameter.options" :key="option" :value="option">
          {{ option }}
        </SelectItem>
      </SelectContent>
    </Select>

    <!-- Integer input -->
    <Input v-else-if="parameter.inputType === 'integer'" :id="paramKey" type="number" :min="parameter.min"
      :max="parameter.max" :model-value="value ?? ''" @update:model-value="handleNumberInput" :placeholder="parameter.placeholder" />

    <!-- Ktext input (text with validation) -->
    <Input v-else-if="parameter.inputType === 'ktext'" :id="paramKey" type="text" :model-value="value ?? ''"
      @update:model-value="handleTextInput" :placeholder="parameter.placeholder" />

    <!-- Boolean input -->
    <div v-else-if="parameter.inputType === 'boolean'" class="flex items-center space-x-2">
      <Switch :id="paramKey" :checked="Boolean(value)" @update:checked="handleSwitchChange" />
      <Label :for="paramKey" class="text-sm font-normal cursor-pointer">
        Enable {{ parameter.label }}
      </Label>
    </div>
  </div>
</template>
