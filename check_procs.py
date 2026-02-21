import subprocess

def get_python_processes():
    try:
        # Get process list from tasklist
        result = subprocess.run(['wmic', 'process', 'where', "name='python.exe'", 'get', 'CommandLine,ProcessId'], capture_output=True, text=True)
        print(result.stdout)
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    get_python_processes()
