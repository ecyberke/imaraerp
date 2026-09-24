<script setup>
// Materialize template gap, pre-existing and unrelated to Imara ERP:
// this checkout's demo directory only ever shipped
// DemoFormWizardIconsBasic.vue - the Validation/Vertical variant files
// this page originally imported don't exist here, which broke
// `npm run build` outright (discovered now that the Node/Vite version
// gap blocking any build at all is finally resolved). Trimmed to the
// variant that actually exists rather than reconstructing two
// generic template demo components no Imara ERP screen depends on.
import {
  basic,
} from '@/views/demos/forms/form-wizard/form-wizard-icons/demoCodeFormWizardIcons'
import DemoFormWizardIconsBasic from '@/views/demos/forms/form-wizard/form-wizard-icons/DemoFormWizardIconsBasic.vue'
</script>

<template>
  <VRow>
    <!-- 👉 Basic -->
    <VCol cols="12">
      <AppCardCode
        variant="outlined"
        title="Basic"
        :code="basic"
      >
        <DemoFormWizardIconsBasic />
      </AppCardCode>
    </VCol>
  </VRow>
</template>
