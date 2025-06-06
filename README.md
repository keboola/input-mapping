# Input Mapping [![Build Status](https://dev.azure.com/keboola-dev/input-mapping/_apis/build/status/keboola.input-mapping?branchName=master)](https://dev.azure.com/keboola-dev/input-mapping/_build/latest?definitionId=37&branchName=master)

Input mapping library for Docker Runner and Sandbox Loader. 
Library processes input mapping, exports data from Storage tables into CSV files and files from Storage file uploads. 
Exported files are stored in local directory.

## Development

Create `.env.local` file from this `.env` template and fill the missing envs:

```ini
cp .env .env.local
```

Run test suite:

```
composer ci
```

## License

MIT licensed, see [LICENSE](./LICENSE) file.
