"""
Test GPT Vision Reader Standalone.
Can be executed from any folder.
"""

import os
import sys

# Ensure project root is in sys.path
PROJECT_ROOT = os.path.dirname(os.path.abspath(__file__))
if PROJECT_ROOT not in sys.path:
    sys.path.insert(0, PROJECT_ROOT)

from lpr_engine.gpt_vision_reader import GPTVisionReader

def main():
    print("=" * 60)
    print("  TESTING GPT VISION READER (FOURTH READER)")
    print("=" * 60)
    
    reader = GPTVisionReader()
    
    print(f"\nModel:      {reader.model}")
    print(f"Timeout:    {reader.timeout}s")
    print(f"Configured: {reader.is_configured()}")
    
    if reader.is_configured():
        print("\n[SUCCESS] GPT Vision is READY and properly configured with your API key!")
    else:
        print("\n[NOTE] GPT Vision is currently in STANDBY mode.")
        print("Please add your OpenAI API key in openai_config.json to activate it.")
        print("Path: " + os.path.join(PROJECT_ROOT, "openai_config.json"))

if __name__ == "__main__":
    main()

