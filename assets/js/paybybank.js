/**
 * Pay by bank at checkout.
 *
 * Asks our own server for a linking session, hands the resulting sessionUrl to the provider's
 * SDK, and posts the customer id back once the customer returns from their bank. No account
 * number is ever present on this page: the customer authenticates with their bank inside the
 * provider's own flow.
 *
 * The provider's SDK is loaded from their CDN only when this rail is actually chosen, so a
 * checkout paying by card never fetches it.
 */
(function () {
  "use strict";

  var SDK_URL = "https://static.link.money/linkmoney-web/v1/latest/linkmoney-web.min.js";
  var sdk = null;

  function loadSdk() {
    if (sdk) return sdk;
    sdk = import(/* webpackIgnore: true */ SDK_URL).then(function (m) {
      return m.default || m;
    });
    return sdk;
  }

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
    if (root.dataset.mounted === "1") return;
    // Already linked: nothing to mount, the token is on the form.
    if (root.querySelector(".compound-pbb__token").value) {
      root.dataset.mounted = "1";
      return;
    }
    root.dataset.mounted = "1";
    var host = root.querySelector(".compound-pbb__button");
    if (!host) return;

    var trigger = document.createElement("button");
    trigger.type = "button";
    trigger.className = "button compound-pbb__start";
    trigger.textContent = "Link your bank";
    trigger.addEventListener("click", function () {
      trigger.disabled = true;
      setStatus(root, "Opening your bank...");
      post(root, "compound_pbb_session", {})
        .then(function (res) {
          if (!res || !res.success) {
            trigger.disabled = false;
            setStatus(root, (res && res.data && res.data.message) || "Could not start bank linking.");
            return;
          }
          // Sandbox simulation: there is no provider SDK to load, and the session URL is
          // the merchant's own redirect with a customer id on it, which is the same shape
          // the real flow returns the customer in.
          if (res.data.simulated) {
            window.location.assign(res.data.session_url);
            return;
          }
          return loadSdk().then(function (Link) {
            var link = Link.LinkInstance({
              sessionUrl: res.data.session_url,
              environment: root.dataset.environment,
              sessionVersion: 2,
            });
            host.innerHTML = "";
            host.appendChild(link.createButton());
            setStatus(root, "Continue with your bank to finish linking.");
            trigger.remove();
          });
        })
        .catch(function () {
          trigger.disabled = false;
          setStatus(root, "Could not start bank linking. Please try again.");
        });
    });
    host.appendChild(trigger);
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
      var chosen = scope.querySelector('input[name="compound_method"]:checked');
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
