/** Show only the document fields of the chosen voucher. Without JS both groups stay visible. */
(() => {
  const voucher = document.getElementById('billing_voucher_type');
  if (!voucher) return;

  const idType = document.getElementById('billing_id_type');
  const idNumber = document.getElementById('billing_id_number');

  const syncVoucher = () => {
    const isFactura = voucher.value === 'FACTURA';
    document.querySelectorAll('.frave-peru-boleta').forEach((row) => {
      row.style.display = isFactura ? 'none' : '';
    });
    document.querySelectorAll('.frave-peru-factura').forEach((row) => {
      row.style.display = isFactura ? '' : 'none';
    });
  };

  const syncIdInput = () => {
    if (!idType || !idNumber) return;
    const isDni = idType.value === 'DNI';
    idNumber.inputMode = isDni ? 'numeric' : 'text';
    idNumber.maxLength = isDni ? 8 : 12;
  };

  voucher.addEventListener('change', syncVoucher);
  idType?.addEventListener('change', syncIdInput);
  syncVoucher();
  syncIdInput();
})();
