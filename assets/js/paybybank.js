/**
 * Pay by bank at checkout.
 *
 * A returning customer's linked bank shows on the form already (rendered server-side, see
 * class-wc-compound-paybybank.php's render_field) and charges synchronously on submit - no
 * script involvement needed for that case. A first-time customer links no bank on this page at
 * all: Link Money's hosted session requires a real payment amount to even start (there is no
 * link-only mode for a first purchase), so placing the order is itself what starts that
 * session, via a redirect after process_payment() runs. This script's only remaining job is
 * finishing that link if the customer somehow lands back on the checkout page with a bank's
 * customerId still on the URL (a stale bookmark, or a redirect target from before this file's
 * order-first behavior shipped) - the normal case now returns to the order-received page
 * instead, where the woocommerce_thankyou hook finishes the link server-side.
 */
(function () {
  "use strict";

  function post(root, action, extra) {
    var body = new URLSearchParams(
      Object.assign({ action: action, nonce: root.dataset.nonce }, fieldsFromCheckout(), extra || {})
    );
    return fetch(root.dataset.ajax, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
    }).then(function (r) {
      return r.json();
    });
  }

  // Read the shopper's details straight from the checkout form so the linking session carries
  // the same name and email the order will. A mismatch would fail server-side verification,
  // which is the correct outcome but a confusing one to hit by accident.
  function fieldsFromCheckout() {
    function val(id) {
      var el = document.getElementById(id);
      return el ? el.value : "";
    }
    return {
      first_name: val("billing_first_name"),
      last_name: val("billing_last_name"),
      email: val("billing_email"),
    };
  }

  function setStatus(root, text) {
    var el = root.querySelector(".compound-pbb__status");
    if (el) el.textContent = text;
  }

  function onLinked(root, customerId) {
    setStatus(root, "Confirming your bank...");
    post(root, "compound_pbb_link", { customer_id: customerId }).then(function (res) {
      if (!res || !res.success) {
        setStatus(root, (res && res.data && res.data.message) || "Could not confirm the bank link.");
        return;
      }
      var d = res.data;
      root.querySelector(".compound-pbb__token").value = d.bank_account_token;
      var label = [d.bank_name, d.account_last4 ? "••••" + d.account_last4 : ""]
        .filter(Boolean)
        .join(" ");
      setStatus(root, label || "Your bank account is linked.");
      var host = root.querySelector(".compound-pbb__button");
      if (host) host.innerHTML = "";
    });
  }

  function mount(root) {
    // Nothing to bind: an already-linked bank is rendered on the form as-is, and a
    // first-time customer has no pre-order action to take on this page (see the file
    // doc comment above). Kept as a distinct function/dataset flag for consumeRedirect's
    // benefit, which still calls this once it has handled any stale redirect.
    root.dataset.mounted = "1";
  }

  // The provider returns its customer id in the redirect's query parameters. Consume it, tell
  // our server, and strip it from the URL so a refresh does not re-post a stale id.
  function consumeRedirect(root) {
    var params = new URLSearchParams(window.location.search);
    var customerId = params.get("customerId") || params.get("customer_id");
    if (!customerId) return false;
    params.delete("customerId");
    params.delete("customer_id");
    var qs = params.toString();
    window.history.replaceState({}, "", window.location.pathname + (qs ? "?" + qs : ""));
    onLinked(root, customerId);
    return true;
  }

  // Only show the panel when its rail is chosen. The radios are the gateway's own
  // (name="compound_method").
  //
  // The lookup is scoped to the panel's own form rather than the document: a checkout can
  // carry more than one set of these radios (the classic gateway and the blocks gateway can
  // both be present), and a document-wide :checked picks whichever came first, which is how
  // the panel ended up hidden while Pay by bank was visibly selected.
  function syncVisibility() {
    document.querySelectorAll(".compound-pbb-panel").forEach(function (panel) {
      var scope = panel.closest("form") || document;
      // A checked radio when there is a choice, or the hidden input the gateway renders when
      // there is only one rail. A hidden input is never :checked, so asking only for that
      // would hide the panel on the single-rail checkout it is the whole point of.
      var chosen =
        scope.querySelector('input[name="compound_method"]:checked') ||
        scope.querySelector('input[type="hidden"][name="compound_method"]');
      var visible = !!chosen && panel.dataset.method === chosen.value;
      panel.hidden = !visible;
      // Mount when it becomes visible, not only when the page settles. WooCommerce swaps the
      // payment area in and out, and a panel that appears after the last scan would otherwise
      // show its status text with no button under it.
      if (visible) {
        var root = panel.querySelector(".compound-pbb");
        if (root && !consumeRedirect(root)) mount(root);
      }
    });
  }

  function scan() {
    syncVisibility();
    document.querySelectorAll(".compound-pbb").forEach(function (root) {
      if (!consumeRedirect(root)) mount(root);
    });
  }

  document.addEventListener("change", function (e) {
    if (e.target && e.target.name === "compound_method") syncVisibility();
  });

  // DOMContentLoaded may already have fired by the time a footer script runs, in which case
  // the listener alone would never fire and nothing would ever mount.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", scan);
  } else {
    scan();
  }
  // WooCommerce replaces the payment area whenever totals or the chosen method change.
  if (window.jQuery) {
    window.jQuery(document.body).on("updated_checkout payment_method_selected", scan);
  }
})();
