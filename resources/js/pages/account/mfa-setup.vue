<script setup>
definePage({
  meta: { action: 'read', subject: 'Dashboard' },
})

const secret = ref('')
const otpauthUrl = ref('')
const code = ref('')
const step = ref(1) // 1 = generate secret, 2 = confirm code
const error = ref('')
const success = ref(false)

const startSetup = async () => {
  error.value = ''
  try {
    const res = await $api('/mfa/setup', { method: 'POST' })

    secret.value = res.secret
    otpauthUrl.value = res.otpauth_url
    step.value = 2
  } catch (err) {
    error.value = err?.data?.message || 'Could not start MFA setup.'
  }
}

const confirmCode = async () => {
  error.value = ''
  try {
    await $api('/mfa/confirm', { method: 'POST', body: { code: code.value } })
    success.value = true
  } catch (err) {
    error.value = err?.data?.message || 'Invalid code - check your authenticator app and try again.'
  }
}
</script>

<template>
  <VRow>
    <VCol
      cols="12"
      md="6"
    >
      <VCard title="Multi-Factor Authentication">
        <VCardText>
          <p class="mb-4">
            Finance and Admin accounts must enable an authenticator app before they can create or approve financial records (invoices, payments, journal entries).
          </p>

          <VAlert
            v-if="success"
            type="success"
            class="mb-4"
          >
            MFA is now enabled on your account.
          </VAlert>

          <VAlert
            v-if="error"
            type="error"
            class="mb-4"
          >
            {{ error }}
          </VAlert>

          <div v-if="step === 1 && !success">
            <VBtn @click="startSetup">
              Start MFA Setup
            </VBtn>
          </div>

          <div v-else-if="!success">
            <p class="mb-2">
              Scan this with an authenticator app (Google Authenticator, Authy), or enter the secret manually:
            </p>
            <VTextField
              :model-value="secret"
              label="Secret key"
              readonly
              class="mb-2"
            />
            <VTextField
              :model-value="otpauthUrl"
              label="Setup link"
              readonly
              class="mb-4"
            />

            <VTextField
              v-model="code"
              label="6-digit code from your app"
              class="mb-4"
              maxlength="6"
            />
            <VBtn @click="confirmCode">
              Confirm and Enable
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
