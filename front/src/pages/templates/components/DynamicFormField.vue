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

const handleNumberInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  const num = parseInt(target.value)
  value.value = isNaN(num) ? null : num
}

const handleTextInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  value.value = target.value || null
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
    <Select v-if="parameter.input_type === 'select'" :model-value="String(value ?? '')"
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
    <Input v-else-if="parameter.input_type === 'integer'" :id="paramKey" type="number" :min="parameter.min"
      :max="parameter.max" :value="value ?? ''" @input="handleNumberInput" :placeholder="parameter.placeholder" />

    <!-- Ktext input (text with validation) -->
    <Input v-else-if="parameter.input_type === 'ktext'" :id="paramKey" type="text" :value="value ?? ''"
      @input="handleTextInput" :placeholder="parameter.placeholder" />

    <!-- Boolean input -->
    <div v-else-if="parameter.input_type === 'boolean'" class="flex items-center space-x-2">
      <Switch :id="paramKey" :checked="Boolean(value)" @update:checked="handleSwitchChange" />
      <Label :for="paramKey" class="text-sm font-normal cursor-pointer">
        Enable {{ parameter.label }}
      </Label>
    </div>
  </div>
</template>
