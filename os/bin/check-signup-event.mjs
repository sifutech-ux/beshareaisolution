import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";

const source = readFileSync(new URL("../../whatsapp/ruang.js", import.meta.url), "utf8");
const start = source.indexOf("function metaText");
const end = source.indexOf("function openEmbeddedSignup");
if (start < 0 || end < start) {
  console.log("FAIL signup helpers are present");
  process.exit(1);
}
const context = createContext({});
runInContext(source.slice(start, end), context);

let failures = 0;
const check = (name, ok) => {
  console.log((ok ? "OK " : "FAIL ") + name);
  if (!ok) failures += 1;
};

const reported = context.signupDiagnostic({
  error_message: "Your verified name violates WhatsApp guidelines.",
  error_code: "524126",
  error_id: "LEGACY",
  session_id: "f34b51dab5e0498"
});
check("documented cancel prefers error_code", reported.error_code === "524126" && reported.session_id === "f34b51dab5e0498");
check("diagnostic result has no message field", !Object.hasOwn(reported, "error_message"));

const numeric = context.signupDiagnostic({ error_code: 524126, session_id: "bad id" });
check("numeric error code is kept and an unsafe session id is dropped", numeric.error_code === "524126" && numeric.session_id === "");

const legacy = context.signupDiagnostic({ error_id: "ERR_LEGACY" });
check("legacy error id remains a fallback", legacy.error_code === "ERR_LEGACY" && legacy.session_id === "");

const fields = context.signupEventFields({
  type: "WA_EMBEDDED_SIGNUP",
  event: "CANCEL",
  data: {
    error_code: 524126,
    current_step: "PHONE_NUMBER_SETUP",
    waba_id: 100000000000101,
    phone_number_id: "100000000000202",
    error_message: "do not keep"
  }
});
check(
  "cancel event fields keep ids and omit the message",
  fields.event === "CANCEL"
    && fields.error_code === "524126"
    && fields.current_step === "PHONE_NUMBER_SETUP"
    && fields.waba_id === "100000000000101"
    && fields.phone_number_id === "100000000000202"
    && !Object.hasOwn(fields, "error_message")
);

const click = source.slice(source.indexOf("function openEmbeddedSignup"), source.indexOf("function settleSignup"));
const loginAt = click.indexOf("window.FB.login");
const thenAt = click.indexOf("started.then");
check("embedded signup opens on the click before the session response", loginAt > 0 && thenAt > loginAt);

process.exit(failures === 0 ? 0 : 1);
