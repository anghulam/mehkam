# Fix Custom Features Selection Bug (Count stays 0)

## Current Status
✅ Plan approved by user

## Tasks
- [✅] 1. Fix pricing.php: Add debug logging + force recalc in pubCalc()
- [✅] 2. Fix register.php: Add logging + fix toggleFeat()/calcTotal() 
- [⚠️] 3. Test both pages in browser (SKIPPED: browser tool disabled)
- [ ] 4. Remove debug logs after confirmation

## Test Instructions
1. Open http://localhost/mehkam/public/pricing.php 
2. Open DevTools (F12) → Console tab
3. Select features → check console logs + verify count updates from 0
4. Test register.php?custom=1 similarly

## Next Step
Clean up debug console.log statements

## Next Step
Edit public/pricing.php with debug version of pubCalc()
