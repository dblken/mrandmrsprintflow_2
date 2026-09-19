import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
html = (root / "staff/customizations.php").read_text(encoding="utf-8")
svc = (root / "staff/partials/service_order_modal.php").read_text(encoding="utf-8")
marker = '<div id="staffJoCustomizationsPage"'
start = html.index(marker)
end = html.index("<!-- /#staffJoCustomizationsPage -->") + len("<!-- /#staffJoCustomizationsPage -->")
chunk = html[start:end] + svc
lines = chunk.splitlines()
depth = 0
max_depth = 0
issues = []
for idx, line in enumerate(lines, start=1):
    opens = len(re.findall(r"<div\b", line, re.I))
    closes = len(re.findall(r"</div>", line, re.I))
    prev = depth
    depth += opens - closes
    max_depth = max(max_depth, depth)
    if opens or closes:
        orig_line = start // 1  # placeholder
    if depth < 0:
        issues.append((idx, depth, line.strip()[:120]))
print("final depth", depth, "max", max_depth)
# map chunk line to file line approximately
file_start = html[:start].count("\n") + 1
for idx, line in enumerate(lines, 1):
    opens = len(re.findall(r"<div\b", line, re.I))
    closes = len(re.findall(r"</div>", line, re.I))
    if not opens and not closes:
        continue
    # running depth
print("file_start", file_start)
# find last lines where depth increases without matching decrease before end
depth = 0
stack = []
for idx, line in enumerate(lines, 1):
    opens = len(re.findall(r"<div\b", line, re.I))
    closes = len(re.findall(r"</div>", line, re.I))
    for _ in range(opens):
        stack.append((file_start + idx - 1, line.strip()[:100]))
    for _ in range(closes):
        if stack:
            stack.pop()
print("unclosed divs", len(stack))
for item in stack[-5:]:
    print(" ", item)
