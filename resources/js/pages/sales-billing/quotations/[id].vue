<script setup>
/* eslint-disable camelcase -- these object keys are literal Laravel API request/response field names (snake_case is the real contract), not JS identifiers to rename. */
definePage({
  meta: { action: 'read', subject: 'SalesBilling' },
})

const route = useRoute('sales-billing-quotations-id')
const orderId = computed(() => route.params.id)

const order = ref(null)
const deliveries = ref([])
const invoices = ref([])
const warehouses = ref([])
const bankAccounts = ref([])
const loading = ref(true)
const notice = ref('')
const noticeType = ref('success')

function flash(message, type = 'success') {
  notice.value = message
  noticeType.value = type
  setTimeout(() => { notice.value = '' }, 6000)
}

async function loadEverything() {
  loading.value = true
  try {
    const [o, d, i, w, b] = await Promise.all([
      $api(`/sales-orders/${orderId.value}`),
      $api(`/sales-orders/${orderId.value}/deliveries`),
      $api('/invoices', { query: { sales_order_id: orderId.value } }),
      $api('/warehouses'),
      $api('/bank-accounts'),
    ])

    order.value = o
    deliveries.value = d
    invoices.value = i
    warehouses.value = w
    bankAccounts.value = b
  } finally {
    loading.value = false
  }
}

async function reload() {
  const [o, d, i] = await Promise.all([
    $api(`/sales-orders/${orderId.value}`),
    $api(`/sales-orders/${orderId.value}/deliveries`),
    $api('/invoices', { query: { sales_order_id: orderId.value } }),
  ])

  order.value = o
  deliveries.value = d
  invoices.value = i
}

// --- Feasibility ---
const assessment = ref({ result: 'passed', notes: '' })
const submitting = ref(false)

async function submitForFeasibility() {
  submitting.value = true
  try {
    await $api(`/sales-orders/${orderId.value}/submit-for-feasibility`, { method: 'POST' })
    await reload()
    flash('Submitted for feasibility check.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function submitAssessment() {
  submitting.value = true
  try {
    await $api(`/sales-orders/${orderId.value}/feasibility-assessments`, { method: 'POST', body: assessment.value })
    await reload()
    flash('Feasibility assessment recorded.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function resubmit() {
  submitting.value = true
  try {
    await $api(`/sales-orders/${orderId.value}/resubmit`, { method: 'POST' })
    await reload()
    flash('Resubmitted for feasibility check.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Reserve stock ---
const reserveWarehouseId = ref(null)

async function reserveStock() {
  submitting.value = true
  try {
    await $api(`/sales-orders/${orderId.value}/reserve-stock`, { method: 'POST', body: { warehouse_id: reserveWarehouseId.value } })
    await reload()
    flash('Stock reserved against this order.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Deliveries ---
const deliveryWarehouseId = ref(null)
const deliveryLineForms = ref({}) // deliveryId -> { sales_order_line_id, quantity_delivered }

async function createDelivery() {
  submitting.value = true
  try {
    await $api(`/sales-orders/${orderId.value}/deliveries`, { method: 'POST', body: { warehouse_id: deliveryWarehouseId.value } })
    await reload()
    flash('Delivery created.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

function deliveryLineForm(deliveryId) {
  if (!deliveryLineForms.value[deliveryId])
    deliveryLineForms.value[deliveryId] = { sales_order_line_id: null, quantity_delivered: 1 }

  return deliveryLineForms.value[deliveryId]
}

async function addDeliveryLine(delivery) {
  submitting.value = true
  try {
    await $api(`/deliveries/${delivery.id}/lines`, { method: 'POST', body: deliveryLineForm(delivery.id) })
    await reload()
    flash('Delivery line added.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function markDelivered(delivery) {
  submitting.value = true
  try {
    await $api(`/deliveries/${delivery.id}/mark-delivered`, { method: 'POST' })
    await reload()
    flash('Delivery marked as delivered.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Invoices ---
const invoiceForm = ref({ payment_terms: 'cash' })

async function createInvoice() {
  submitting.value = true
  try {
    const lines = order.value.lines.map(l => ({
      description: l.description,
      quantity: l.quantity,
      unit_price: l.rate,
      source_type: 'SalesOrderLine',
      source_id: l.id,
    }))

    await $api('/invoices', {
      method: 'POST',
      body: { sales_order_id: orderId.value, payment_terms: invoiceForm.value.payment_terms, lines },
    })
    await reload()
    flash('Invoice created from this order\'s line items.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function requestCreditApproval(invoice) {
  submitting.value = true
  try {
    const approval = await $api('/credit-approvals', { method: 'POST', body: { invoice_id: invoice.id } })

    await reload()
    flash(approval.status === 'approved' ? 'Credit approved.' : 'Credit check did not pass - a Finance user can override it.', approval.status === 'approved' ? 'success' : 'warning')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

async function raiseInvoice(invoice) {
  submitting.value = true
  try {
    await $api(`/invoices/${invoice.id}/raise`, { method: 'POST' })
    await reload()
    flash('Invoice raised and posted to the ledger.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

// --- Payment ---
const paymentDialogInvoice = ref(null)
const paymentForm = ref({ amount: 0, method: 'bank_transfer', bank_account_id: null })

function openPaymentDialog(invoice) {
  paymentDialogInvoice.value = invoice
  paymentForm.value = { amount: invoice.net_payable, method: 'bank_transfer', bank_account_id: null }
}

async function recordPayment() {
  submitting.value = true
  try {
    await $api('/payments', {
      method: 'POST',
      body: {
        party_id: order.value.party_id,
        invoice_allocations: [{ invoice_id: paymentDialogInvoice.value.id, amount: paymentForm.value.amount }],
        method: paymentForm.value.method,
        bank_account_id: paymentForm.value.bank_account_id,
      },
    })
    paymentDialogInvoice.value = null
    await reload()
    flash('Payment recorded.')
  } catch (err) {
    flash(extractApiErrorMessage(err), 'error')
  } finally {
    submitting.value = false
  }
}

const invoiceStatusColor = status => ({
  draft: 'secondary', raised: 'primary', partially_paid: 'warning', paid: 'success', written_off: 'error',
}[status] || 'secondary')

onMounted(loadEverything)
</script>

<template>
  <div v-if="loading">
    <VProgressCircular indeterminate />
  </div>

  <div v-else-if="order">
    <VAlert
      v-if="notice"
      :type="noticeType"
      class="mb-4"
      closable
      @click:close="notice = ''"
    >
      {{ notice }}
    </VAlert>

    <VCard
      class="mb-4"
      :title="`Quotation ${order.document_number || '#' + order.id}`"
    >
      <template #subtitle>
        Status: {{ order.status.replaceAll('_', ' ') }} · Supply path: {{ order.supply_path.replaceAll('_', ' ') }}
      </template>
      <VCardText>
        <p><strong>Total:</strong> KES {{ order.total }}</p>
        <VTable density="compact">
          <thead>
            <tr>
              <th>Description</th>
              <th>Qty</th>
              <th>Rate</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="line in order.lines"
              :key="line.id"
            >
              <td>{{ line.description }}</td>
              <td>{{ line.quantity }}</td>
              <td>{{ line.rate }}</td>
              <td>{{ line.amount }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!-- Feasibility -->
    <VCard
      v-if="['draft', 'renegotiating'].includes(order.status)"
      class="mb-4"
      title="Feasibility Check"
    >
      <VCardText>
        <VBtn
          v-if="order.status === 'draft'"
          :loading="submitting"
          @click="submitForFeasibility"
        >
          Submit for Feasibility Check
        </VBtn>
        <VBtn
          v-else
          :loading="submitting"
          @click="resubmit"
        >
          Resubmit for Feasibility Check
        </VBtn>
      </VCardText>
    </VCard>

    <VCard
      v-if="order.status === 'feasibility_check'"
      class="mb-4"
      title="Record Feasibility Assessment"
    >
      <VCardText>
        <VSelect
          v-model="assessment.result"
          label="Result"
          class="mb-4"
          :items="[
            { title: 'Passed', value: 'passed' },
            { title: 'Rejected', value: 'rejected' },
            { title: 'Needs Renegotiation', value: 'renegotiating' },
          ]"
        />
        <VTextarea
          v-model="assessment.notes"
          label="Notes"
          class="mb-4"
        />
        <VBtn
          :loading="submitting"
          @click="submitAssessment"
        >
          Record Assessment
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Reserve Stock (Direct Sale / Manufacture for Sale) -->
    <VCard
      v-if="order.status === 'approved' && ['direct_sale', 'manufacture_for_sale'].includes(order.supply_path)"
      class="mb-4"
      title="Reserve Stock"
    >
      <VCardText>
        <VSelect
          v-model="reserveWarehouseId"
          label="Warehouse"
          class="mb-4"
          item-title="name"
          item-value="id"
          :items="warehouses"
        />
        <VBtn
          :loading="submitting"
          :disabled="!reserveWarehouseId"
          @click="reserveStock"
        >
          Reserve Stock for This Order
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Deliveries -->
    <VCard
      v-if="!['draft', 'feasibility_check', 'rejected', 'renegotiating'].includes(order.status)"
      class="mb-4"
      title="Deliveries"
    >
      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="deliveryWarehouseId"
              label="Warehouse"
              item-title="name"
              item-value="id"
              :items="warehouses"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
            class="d-flex align-center"
          >
            <VBtn
              :loading="submitting"
              :disabled="!deliveryWarehouseId"
              @click="createDelivery"
            >
              New Delivery
            </VBtn>
          </VCol>
        </VRow>

        <VCard
          v-for="delivery in deliveries"
          :key="delivery.id"
          variant="outlined"
          class="mb-4"
        >
          <VCardText>
            <p class="mb-2">
              <strong>Delivery #{{ delivery.id }}</strong> - {{ delivery.status }}
            </p>

            <VTable
              v-if="delivery.lines.length"
              density="compact"
              class="mb-2"
            >
              <thead>
                <tr>
                  <th>Line</th>
                  <th>Qty Delivered</th>
                  <th>COGS</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="l in delivery.lines"
                  :key="l.id"
                >
                  <td>#{{ l.sales_order_line_id }}</td>
                  <td>{{ l.quantity_delivered }}</td>
                  <td>{{ l.cogs_value }}</td>
                </tr>
              </tbody>
            </VTable>

            <VRow
              v-if="delivery.status === 'pending'"
              align="center"
            >
              <VCol
                cols="12"
                md="5"
              >
                <VSelect
                  v-model="deliveryLineForm(delivery.id).sales_order_line_id"
                  label="Order Line"
                  item-title="description"
                  item-value="id"
                  :items="order.lines"
                />
              </VCol>
              <VCol
                cols="6"
                md="3"
              >
                <VTextField
                  v-model.number="deliveryLineForm(delivery.id).quantity_delivered"
                  type="number"
                  label="Quantity"
                />
              </VCol>
              <VCol
                cols="6"
                md="2"
              >
                <VBtn
                  :loading="submitting"
                  @click="addDeliveryLine(delivery)"
                >
                  Add Line
                </VBtn>
              </VCol>
              <VCol
                cols="12"
                md="2"
              >
                <VBtn
                  variant="tonal"
                  :loading="submitting"
                  :disabled="!delivery.lines.length"
                  @click="markDelivered(delivery)"
                >
                  Mark Delivered
                </VBtn>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCardText>
    </VCard>

    <!-- Invoices -->
    <VCard
      v-if="!['draft', 'feasibility_check', 'rejected', 'renegotiating'].includes(order.status)"
      class="mb-4"
      title="Invoices & Payments"
    >
      <VCardText>
        <VRow
          v-if="!invoices.length"
          class="mb-4"
        >
          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="invoiceForm.payment_terms"
              label="Payment Terms"
              :items="[
                { title: 'Cash', value: 'cash' },
                { title: 'Credit', value: 'credit' },
              ]"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
            class="d-flex align-center"
          >
            <VBtn
              :loading="submitting"
              @click="createInvoice"
            >
              Create Invoice from Order Lines
            </VBtn>
          </VCol>
        </VRow>

        <VCard
          v-for="invoice in invoices"
          :key="invoice.id"
          variant="outlined"
          class="mb-4"
        >
          <VCardText>
            <p class="mb-2">
              <strong>{{ invoice.document_number || `Invoice #${invoice.id}` }}</strong>
              <VChip
                class="ms-2"
                :color="invoiceStatusColor(invoice.status)"
              >
                {{ invoice.status.replaceAll('_', ' ') }}
              </VChip>
            </p>
            <p>Net Payable: KES {{ invoice.net_payable }}</p>
            <p>Credit approval: {{ invoice.credit_approval_status.replaceAll('_', ' ') }}</p>

            <VBtn
              v-if="invoice.status === 'draft' && invoice.credit_approval_status === 'pending'"
              class="me-2"
              :loading="submitting"
              @click="requestCreditApproval(invoice)"
            >
              Request Credit Approval
            </VBtn>

            <VBtn
              v-if="invoice.status === 'draft'"
              class="me-2"
              :loading="submitting"
              @click="raiseInvoice(invoice)"
            >
              Raise Invoice
            </VBtn>

            <VBtn
              v-if="['raised', 'partially_paid'].includes(invoice.status)"
              @click="openPaymentDialog(invoice)"
            >
              Record Payment
            </VBtn>
          </VCardText>
        </VCard>
      </VCardText>
    </VCard>

    <VDialog
      :model-value="!!paymentDialogInvoice"
      max-width="450"
      @update:model-value="paymentDialogInvoice = null"
    >
      <VCard title="Record Payment">
        <VCardText>
          <VTextField
            v-model.number="paymentForm.amount"
            type="number"
            label="Amount (KES)"
            class="mb-4"
          />
          <VSelect
            v-model="paymentForm.method"
            label="Method"
            class="mb-4"
            :items="[
              { title: 'Bank Transfer', value: 'bank_transfer' },
              { title: 'Cash', value: 'cash' },
              { title: 'Cheque', value: 'cheque' },
              { title: 'M-Pesa', value: 'mpesa' },
            ]"
          />
          <VSelect
            v-if="paymentForm.method === 'bank_transfer'"
            v-model="paymentForm.bank_account_id"
            label="Bank Account"
            item-title="bank_name"
            item-value="id"
            :items="bankAccounts"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="paymentDialogInvoice = null"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="submitting"
            @click="recordPayment"
          >
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
