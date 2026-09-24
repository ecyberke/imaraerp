// Laravel's standard error shapes: a 422 validation failure returns
// { message, errors: { field: [msg, ...] } }; everything else (403,
// 404, 500, a thrown DomainException) returns { message }. This turns
// either into one display string without ever surfacing a raw field
// name or exception class on screen (§9's naming rule).
export function extractApiErrorMessage(err) {
  const data = err?.data ?? err?._data
  if (!data)
    return err?.message || 'Something went wrong. Please try again.'

  if (data.errors) {
    const firstField = Object.keys(data.errors)[0]

    return data.errors[firstField]?.[0] || data.message || 'Please check the form and try again.'
  }

  return data.message || 'Something went wrong. Please try again.'
}
