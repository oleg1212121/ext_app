// Test harness for tests/Unit/TokenizerParityTest.php — not part of the app
// bundle. Prints the segmentText() output for each argv sample as one JSON
// array, preserving argument order.
import {segmentText} from './wordTokenizer.mjs';

const samples = process.argv.slice(2);
process.stdout.write(JSON.stringify(samples.map(segmentText)));
