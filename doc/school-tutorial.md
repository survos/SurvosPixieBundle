# School Tutorial

Let's start with a csv file and iteratively make it a useful pixie. 

Create a new repo, install the bundle, and create the data and pixie directories

We want the files from here: https://github.com/OfficeDev/O365-EDU-Tools/tree/master/CSV%20Samples

```bash
git clone -n --depth=1 --filter=tree:0 https://github.com/OfficeDev/O365-EDU-Tools temp-edu && cd temp-edu
git sparse-checkout set --no-cone "CSV Samples"
git checkout
mv "CSV Samples" ../data/education
cd ..
rm -rf temp-edu
```

# Setup a configuration file

Pixie needs a configuration file to understand how to import the data.

By default, config files are located in config/packages/pixie/<code> and the source files are at /data/<code>.  The pixie file (a sqlite database) is located at /pixie/<code>.pixie.db

Run the init command to read the source files and create a configuration shell.

```bash
bin/console pixie:init education
# with defaults, the above is the same as 
bin/console pixie:init --config=config/packages/pixie/education.yaml --source=data/education --pixie=pixie/education.pixie.db 
```


