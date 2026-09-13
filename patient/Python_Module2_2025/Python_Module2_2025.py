# PYTHON PROGRAMMING

### Python Programming Basics

# **Objects:**
# - Variables
# - Python Objects
# - Standard Types
# - Other Built-in Types
# - Internal Types
# - Standard Type Operators
# - Standard Type Built-in Functions
# - Categorizing the Standard Types
# - Unsupported Types
 

# **Numbers:**
# - Introduction to Numbers
# - Integers
# - Floating Point Real Numbers
# - Complex Numbers
# - Operators
# - Built-in Functions
# - Related Modules

# **Sequences:**
# - Strings
# - Lists
# - Tuples
# - Mapping 
# - Set Types


## Variables in Python

# - The content to be used for processing (data, functions, etc.) are stored in session memory.
# - A variable is a pointer to this content in memory. It allows us to refer to
#   a stored value by assigning a name to it.
# - Can be referred to as a Named memory locations to store values.
# - Variables can be alphanumeric but must start with an alphabet.
# - Identifiers can be a combination of letters in lowercase (a to z) or 
#   uppercase (A to Z) or digits (0 to 9) or an underscore (_).
# - No special character allowed including spaces, only ‘_’ (underscore) is allowed.
# - In Python variables are directly assigned with a value thanks to its dynamic programming.
# - Assignment operator (=) is used to assign a value on the right side and
#   variable on left side.

# ***Example***:
X = 10
x = 15.6 # Python is case sensitive
abc = 'python' # String data type & size = 6. Single quotes work
xyz = "programming" # double quotes works
ABC = ''python'' # throws error if you have two single quotes
XYZ = '''python''' # triple single quotes
pqr = """python""" # triple double quotes

efg = ''''python'''' # stop at triple single quotes and dont go beyond
efg = """"python"""" # stop at triple double quotes and dont go beyond

def = "interesting" # keywords are not accepted as variable names. 
# def is a keyword, which is used to define a function within Python. 
# We shall learn about function in subsequent modules. 

### Keywords or Reserved Words
# - Keywords or Reserved words are pre-defined variables
# - Keywords are case sensitive
# - Cannot be used as a variable name, function name or any other identifier

import keyword  # Import the keyword module
keyword.kwlist  # Display the list of Python keywords

### Variables Declaration

# - Variable assignment rules
# - Variable name should start with a letter 
# - It should contain only alphanumerics
# - It should not contain symbols except '_'
# - Should not start with a number 
# - Should not have space in between, use ( _ ) instead ex : name_last
# - Should not use any of these symbols "",<>,|,?,!,@,#,%,$,&,*,~,+
# - Should not contain predefined keywords (variables) which have special 
#   meaning in python like "list"
# - Considered best practice use lower case names as variable assignment   


## Variable Examples
car = 10    # Assign the value 10 to the variable 'car'
car
print(car)  # Print the value of the variable 'car'. Print is used to improve readability of code

car 2s = 20  # This line will raise a syntax error because variable names cannot contain spaces or start with a digit.

car$ = 5  # This line will raise a syntax error because variable names cannot contain special characters except underscore.

new_car = 'car'  # Assign the string 'car' to the variable 'new_car'
print('new_car')   # Print the value of the variable 'new_car'

2car = 5 # Variable name cannot start with a number

## Operators in Python

# Operators are the constructs which can manipulate the value of operands
# - Ex: 2 + 3 = 5
#     - where, 2 and 3 are called operands and + is called operator

'''Types of Operators:
# - Arithmetic Operators
# - Comparison (Relational) Operators
# - Assignment Operators
# - Logical Operators
# - Membership Operators
# - Identity Operators
# - Bitwise Operators'''

## Arithmetic Operators

a = 2  # Assign the value 2 to variable 'a'
b = 4  # Assign the value 4 to variable 'b'

# Addition
print(a + b)  # Output: 6 - Prints the result of adding 'a' and 'b'
# print function in Python is used to improve readability of output on console

# Subtraction
print(a - b)  # Output: -2 - Prints the result of subtracting 'b' from 'a'

# Multiplication
print(a * b)  # Output: 8 - Prints the result of multiplying 'a' and 'b'

# Exponential
print(a ** b)  # Output: 8 - Prints 'a' raised to the power of 'b'

# Division 
print(a / b)  # Output: 0.5 - Prints the result of dividing 'a' by 'b'
c = a / b # c is float datatype. 
# even if you divide two integers you will get float value as output

# Floor Division "Number line : e.g. ....-5 -4 -3 -2 -1 0 1 2 3 4 5...."
print(a // b)  # Output: 0 because you get 0.5 when you divide 2 by 4 
# and 0 is the immediate smaller integer on number line
# if the output is an integer then the same will be the floor division output
# if the output is float then the immediate smaller interger is selected as output

a = -2; b = 4 # multiple variables can be defined in a single line
print(a // b) # Output: -1 

a = 2; b = 4 # multiple variables can be defined in a single line
# Modulus - Gives remainder as output
print(b % a)  # Output: 0 - Prints the remainder of dividing 'b' by 'a'

# Modulus
print(a % b)  # Output: 2 - Prints the remainder of dividing 'a' by 'b'


# Comparison Operator - Returns boolean values - True (= 1) & False (= 0)

print(a == b)  # Output: False - Checks if 'a' is equal to 'b'
 
print(a != b)  # Output: True - Checks if 'a' is not equal to 'b'
 
print(a > b)   # Output: False - Checks if 'a' is greater than 'b'
 
print(a < b)   # Output: True - Checks if 'a' is less than 'b'

print(a >= b)  # Output: False - Checks if 'a' is greater than or equal to 'b'

print(a <= b)  # Output: True - Checks if 'a' is less than or equal to 'b'


## Assignment Operators

c = a + b  
print(c)      # Output: 6 - Adds 'a' and 'b', assigns result to 'c'
 
c += b        # Can be read as c = c + b
print(c)      # Output: 10 - Adds 'b' to 'c' and updates 'c'

c -= b
print(c)      # Output: 6 - Subtracts 'b' from 'c' and updates 'c'

c *= b
print(c)      # Output: 24 - Multiplies 'c' by 'b' and updates 'c'

c /= b
print(c)      # Output: 6.0 - Divides 'c' by 'b' and updates 'c'

c %= b
print(c)      # Output: 2.0 - Computes modulus of 'c' with 'b' and updates 'c'

c **= b
print(c)      # Output: 16.0 - Raises 'c' to the power of 'b' and updates 'c'

c //= b
print(c)      # Output: Computes floor division of 'c' by 'b' and updates 'c'


## Membership Operators
# - It will check whether left value is a member of right value or not 

print("y" in "Python")   # Output: True - Checks if 'y' is in the string "Python"

print("l" in "Python")   # Output: False - Checks if 'l' is in the string "Python"

print("p" in "Python")   # Output: False - Checks if 'p' is in the string "Python"

print("P" not in "Python")  # Output: False - Checks if 'P' is not in the string "Python"


## Identity Operators
# - It will check whether left value is equal to the right value or not

"y" is "Python"  # Output: False - Checks if the string "y" is the same object as the string "Python"

import warnings # import the library
warnings.filterwarnings("ignore", category = SyntaxWarning)

"y" is "Python" # No warnings

print(1 is 1)    # Output: True - Checks if the integer 1 is the same object as the integer 1

print(2 is 1)    # Output: False - Checks if the integer 2 is the same object as the integer 1

print("pushpa" is "Pushpa")  # Output: False - Checks if the string "pushpa" is the same object as the string "Pushpa"

print(1 is not 1)  # Output: False - Checks if the integer 1 is not the same object as the integer 1

print("hi hello"  is not  "hello hi")  # Output: True - Checks if the string "hi hello" is not the same object as the string "hello hi"

## Precedence of Operators
# - If an expression contains more than one operator, then order of evaluation 
#   depends on the order of operations
# - `PEMDAS` (Parentheses, Exponentiation, Multiplication, Division, Addition, Subtraction)
#     - Parentheses have the highest precedence and can be used to force an 
#       expression to evaluate in the order that we want
#     - Exponentiation has the next highest precedence
#     - Multiplication and Division have higher precedence than Addition and Subtraction
#     - Operators with the same precedence are evaluated from left to right 
#       (except exponentiation)


## Standard Types

# Python Data Types

# - Every value in Python has a data type. It is a set of values, and the 
#   allowable operations on those values.
# - Data types are an essential concept in any computer programming language.
# - It helps to understand the kind of operations that can be performed.
# - Data Types are of 5 types:
#     - Numbers
#     - Boolean
#     - Sets 
#     - Mapping (Dictionaries)
#     - Sequences


### Numerical Types

# - Integer (Count)              ---> int     ---> Whole numbers such as 3, 300, 200
# - Floating Point (Continuous)  ---> float   ---> Numbers with decimal point 3.56, 2.45, 76.87
# - Complex Numbers              ---> complex ---> Numbers with real and imaginary parts 2 + 3j
# - Boolean                      ---> bool    ---> True or False (True = 1 and False = 0)

# Scalar Data Types. Scalar ('0' dimension) means a single value
# Group of value is called as vector ('1' dimension).

# Integer
print(1)          # Output: 1

# Float
print('Float')
print(11.5)       # Output: 11.5
x = 55.45
print('x')
print(x)          # Output: 55.45
print(type(x))    # Output: <class 'float'>

# Complex number
print('Complex Number')
print(2 + 3j)     # Output: (2 + 3j)
type(2 + 3j)       # Output: <class 'complex'>

# Boolean
# bool --> Logical values indicating True or False
print(type(True))   # Output: <class 'bool'>
print(type(False))  # Output: <class 'bool'>


# Sequence Types
# - String ---> str ---> Ordered sequence of characters
# - Lists ---> list ---> Ordered sequence of objects
# - Tuples ---> tup ---> Ordered immutable sequence of objects


## Python Data Types: Strings

# Strings:
# - A series or sequence of characters - letters, numbers, and special characters.
# - They are marked by quotes: single (' ') or double (" ") or triple (''' '''), (""" """).
# - Characters can be accessed using indexing and slicing operations.
# - Strings are immutable i.e.; the contents of a string cannot be changed once created.
# - Positive indexing starts from 0, and Negative subscript helps in accessing 
#   the string from the end which starts from -1.

### Strings
# Strings are ordered sequence, that means we can use indexing and slicing to 
# grab sub-sections of the string.

# Indexing notation uses square brakets [ ].

# A number to indicate positon of the value we wish to access can be mentioned 
# within square brakets [ ].

a = """My name is Pushpa. Pushpa does not mean flower. Pushpa means wild fire."""

# Run the following two lines of code at one go to understand '\n' significance
print(a, '\n')  # Output: Prints the value of 'a' (multi-line string)
a               # run lines 318 & 319 at one go and then show how line 318 wont show output on console
print(type(a))         # Output: <class 'str'> (prints the data type of 'a')

print('Datatype is', type(a),'\n')  # Output: Prints the data type of 'a'

s1 = 'Aditya is trainer'

print('Length is', len(s1))  # Output: Length is 17 (prints the length of string 's1')

### Syntax of indexing of strings

# - String_name[index]
# - Positive index starts from 0
# - Negative index starts from -1 (Reverse order)

### Slicing of strings

# [start : stop : step] this is called as slicing of strings.

# - Start is a numerical index for slice start.
# - Stop is the index you go upto (but not included).
# - Step is the jump you take.

# Accessing values in string 
Name = "Digitmg"

# Execute the following three lines at one go. 
print('Indexing of strings')
print(Name[0])  # Positive Indexing: Prints the first character of the string
print(Name[3]) # Positive Indexing: Prints the fourth character of the string

print(Name[-1]) # Negative Indexing: Prints the last character of the string

print('Slicing of strings\n')
print(Name[1:4])  # Slicing: Prints characters from index 1 to index 3 (excluding index 4)
print(Name[-3:-1])  # Slicing: Prints characters from index -3 to index -2 (excluding index -1)

# String updation
var1 = 'Hello World! ' # Space is available at the end
var1
var1 + 'Python'  # Concatenation: Appends 'Python' to the end of var1

# Select next three lines of code and run
print('Hello')  # Output: Hello
print("Updated String :- ", var1 + 'Python')  # Output: Updated String :-  Hello World!  Python
print("Updated String :- ", var1[:6] + 'Python')  # Output: Updated String :-  Hello Python

print("hello \nworld")  # Output: hello 
                        #         world (prints 'hello' in one line and 'world' in the next line)

# String Formatting
print('%.3f %s is $%d' %(6.4568, 'python prasad', 1))  # Output: 6.456 python prasad is $1

# String Formatting 
print("My name is %s and weight is %d kgs!" % ('Divit', 20.5))  # Output: My name is Divit and weight is 20 kgs!
print("My name is %s and weight is %f kgs!" % ('Divit', 20.5))
print("My name is %s and weight is %.1f kgs!" % ('Divit', 20.5))

# Example of dynamically entering a value:
Name = input("Enter your name: ") # Asks for user input for their name. Dont enter numbers. 
type(Name)  # Output: str (prints the data type of the input)

Weight = int(input("Enter your Weight: "))  # Asks for user input for their weight and converts it to an integer
type(Weight)  # Output: int (prints the data type of the input)

print("My name is %s and my Weight is %d" %(Name, Weight))

print('This is a string {}' . format('inserted'))

print("My name is {} and my Weight is {}" . format(Name, Weight))

print("My name is {1} and my Weight is {0}" . format(Name, Weight))

print('the output is {r}' . format(r = Weight))

print(f'{Name} is {Weight} kgs') # fstrings

# Triple Quotes and any thing inside including single or double any any quotes does not have any influence
Statement = """My name is "Pushpa" and my age is "25". I stay in the forests of "Red Sandalwood".  
"Pushpa... Pushpa Raj! I don't bow down to anyone!" """

type(Statement)

# Adding spaces - To make string at center in given spaces
Name = 'Aditya'
Name.center(30)
len(Name.center(50))

# count() method returns the number of occurrences of the substring in the given string.
string = "Yashvi is a trainer"
substring = "i"
count = string.count(substring)
print("The count is:", count)

# Count number of occurrences of a given substring using start and end
string = "Aditya is a trainer"
len(string)
substring = "i"

# Count after first 'i' and before the last 'i'
count = string.count(substring, 7, 19)
print("The count is:", count)

# Returns true if string has atleast 1 character and all characters are 
# alphanumeric; false otherwise.

Num = 'thisis34'  # No space in this string
print(Num.isalnum())

Num = "this is string examplehi 123"
print(Num.isalnum())

# This method returns true if all characters in the string are alphabetic and 
# there is atleast one character; false otherwise.
Num = "thisis" # No space & digit in this string
Num.isalpha()

Num = "this is string example" # space is treated as a special character, not alphabet
Num.isalpha()

Num = "this_is_string_example!!!" # _ & ! are treated as special characters
Num.isalpha()

# This method returns true if all characters in the string are digits and there
# is at least one character, false otherwise.
Num = "123456"  # Only digit in this string
Num.isdigit()  

Num = "123 456"
Num.isdigit()  # Output: False, as the string contains non-digit characters

Name = "YASHvi"
type(Name)  # Output: <class 'str'>, indicating that 'Name' is a string

print(Name)  # Output: YASHvi

# String manipulation
print(Name.capitalize())  # Output: Yashvi
print(Name.upper())  # Output: YASHVI
print(Name.lower())  # Output: yashvi
print(Name.swapcase())  # Output: yashVI

# The following example shows the usage of the replace() method.

reply = "this is a string example!!! Is this really a string"

print(reply.replace("is", "was"))
# Output: 'thwas was a string example!!! Is thwas really was a string'

print(reply.replace("is", "was", 2))
# Output: 'thwas was a string example!!! Is this really is a string'

print(":".join(reply))
# Output: 't:h:i:s: :i:s: :a: :s:t:r:i:n:g: :e:x:a:m:p:l:e:!:!:!: :I:s: :t:h:i:s: :r:e:a:l:l:y: :i:s: :a: :s:t:r:i:n:g'

# The following example shows the usage of the split() method.
split1 = "Line1-abcdef Line2-abc \nLine4-abcd"
print(split1)
# Output: Line1-abcdef Line2-abc 
#         Line4-abcd

print(split1.split())
# Output: ['Line1-abcdef', 'Line2-abc', 'Line4-abcd']

print(split1.split(' ', 1 ))
# Output: ['Line1-abcdef', 'Line2-abc \nLine4-abcd']

# End of String data type discussion. You will learn more about strings during Text Mining & NLP Master class

### Python Sequence Data Types: Lists
# Lists:
# - A List is an ordered sequence of items
# - Represented using square brackets [ ]
# - Values in the list are called elements/items
# - It can be written as a list of comma-separated items (values)
# - Items in the list can be of different data types
# - Values of the list are mutable (values can be changed)

list1 = ['ssss', 5, 5.5, 40+55j, True]
print(list1)  # Output: ['ssss', 5, 5.5, (40+55j), True]

# Access values in the variable using index numbers
print(list1[0])  # Output: 'ssss'

print(list1[3])  # Output: (40+55j)

print(list1[1:4])  # Output: [5, 5.5, (40+55j)]

# Append: add new elements to the existing list
aList = [123, 'xyz', 'zara', 'abc']

aList[2] = 55 + 40j # replace the 2nd index value with this new value
print("Updated aList:", aList) # Output: [123, 'xyz', (55+40j), 'abc']

aList.append(2009) # adds new element / item to the end of the list
print("After Append:", aList) # Output: [123, 'xyz', (55+40j), 'abc', 2009]

# Pop: remove the last element from the existing list
print("Popped Element:", aList.pop())  # Output: 2009
print("List after Pop:", aList)  # Output: [123, 'xyz', (55+40j), 'abc']

# Pop the element using index number
print("Popped Element at Index 2:", aList.pop(2))  # Output: (55+40j)
print("List after Index Pop:", aList)  # Output: [123, 'xyz', 'abc']

# Insert: insert a value at a specific index
aList = [123, 'xyz', 'tommy', 'abc', 123]
aList.insert(3, 2009)
print("After Insert:", aList)  # Output: [123, 'xyz', 'tommy', 2009, 'abc', 123]

# Append vs Extend  
# Append
aList = [123, 'xyz', 'tommy', 'abc', 123]
bList = [2009, 'beneli']

aList.append(bList)
print(aList)  # Output: [123, 'xyz', 'tommy', 'abc', 123, [2009, 'beneli']]

# Extend
aList = [123, 'xyz', 'tommy', 'abc', 123]
bList = [2009, 'beneli']

aList.extend(bList)
print(aList)  # Output: [123, 'xyz', 'tommy', 'abc', 123, 2009, 'beneli']

# Reverse: to reverse the given list 
aList = [123, 'xyz', 'tommy', 'abc', 789]
aList.reverse()
print(aList)  # Output: [789, 'abc', 'tommy', 'xyz', 123]

# Sort: sort the given list from ascending or descending
blist = [8, 99, 45, 33]
blist.sort() # by default the parameter "reverse = False"
print(blist) # sort function will update the variable in memory 
blist.sort(reverse = True) # descending order with parameter "reverse = True"
print(blist)  # Output: [99, 45, 33, 8]

# count: count the value in given list of elements
aList = [123, 'xyz', 'zara', 'abc', 123, "zara"]
print(aList.count(123))  # Output: 2

# Index: find the index of the first occurrence of a value
print(aList.index('zara'))  # Output: 2. How to refer to all index numbers of 'zara' will be learnt later

# Arithmetic operations on Lists
list1 = [1, 2, 3]
list2 = [1, 2, 3]

print(1 + 2)  # Output: 3
print('a' + 'b')  # Output: 'ab'. This is how lists math operations will work

list3 = list1 + list2
print(list3)  # Output: [1, 2, 3, 1, 2, 3]

print(list1 * 3)  # Output: [1, 2, 3, 1, 2, 3, 1, 2, 3]

# Display the functions for the list data type
dir(list)

# Help will be your buddy throughout or become a robot to remember any function
help(list)

# Python Data Types: Tuples

# Tuples:
# - A tuple is the same as a list, with some difference
# - Tuple values are enclosed in parentheses ()
# - A tuple is an immutable list i.e., once a tuple is created, it cannot be edited
# - Benefits of Tuple: 
#     - Tuples are faster than lists
#     - If the user wants to protect the data from accidental changes, a tuple can be used
#     - Tuples can be used as keys in dictionaries, while lists can't

# Create a empty tuple
tup1 = ()
tup1

# Create a single tuple
tup1 = (50, )
type(tup1)

# Accessing Values in Tuples
tup1 = ('Aditya', '360digitmg', 'Fiat', 8055, 2 + 3j, False)
print(tup1[0])

tup2 = (11, 12, 13, 14, 15, 16, 17)
print(tup2[1:5])

# Updating Tuples
tup1 = (12, 34.56)

# Tuples are immutable
tup1[1] = 40 # This line will throw error. Tuple is immutable, hence values cannot be changed. 

s = 'sam'
s[0]

s[0] = 'z' # this line will throw error. Tuple is immutable, hence values cannot be changed. 

# Create a new tuple
tup1 = (12, 34.56)
tup2 = ('abc', 'xyz')

tup3 = tup1 + tup2
print(tup3)

# index, count
tup = (1, 2, 3, 4, 5, 2, 2)
print(tup.index(2))  # Output: 0 (index of the first occurrence of 2)
print(tup.count(2))  # Output: 3 (count of occurrences of 2)

# Delete Tuple Elements
tup = ('Aditya', '360digitmg', 'Fiat', 8055)
print(tup)

# Delete the given tuple 
del(tup)
print(tup)

# We are not Rajnikanth from Robot movie. We cannot remember every function
# Hence dir and help will be extremely handy
dir(tuple)
help(tuple) 

### Python Data Types: Sets
# - Sets are used to store multiple items in a single variable.
# - Sets are unindexed
# - Sets are unordered
# - Sets can be altered
# - Sets do not allow duplicates
# - Frozen sets are unchangeable

# Sets --> set ---> Unordered collection of unique objects

# A normal Set
# Define a set containing integers 1, 2, and 3
salman = {1, 2, 3}
print(salman)  # Print the set containing integers 1, 2, and 3

# Define a set using the set() constructor with a list of strings as input
normal_set = set(["a", "b", "c", "f"])
print(normal_set)  # Print the set containing strings 'a', 'b', 'c', and 'f'

# Define a set directly with curly braces containing strings 'a', 'b', 'c', and 'd'
normal_set = {'a', 'b', 'c', 'd'}
print(normal_set)  # Print the set containing strings 'a', 'b', 'c', and 'd'

# Add a duplicate value to the 'set'. No duplicates are allowed in sets, hence you will not see the duplicate entry
# Add element "d" to the set
normal_set.add("d")
print(normal_set)  # Print the updated set

# Converting a list into a set
my_list = [1, 1, 2, 3, 4, 4, 5, 5, 6, 7]
my_set = set(my_list)  # Convert the list into a set to remove duplicates
print(my_set)  # Print the resulting set without duplicates

# Operations on sets
set1 = {1, 2, 3}
set2 = {1, 9, 0}

# Find the intersection of set1 and set2
intersection_set = set1.intersection(set2)
print(intersection_set)  # Print the intersection set

# Convert the intersection set to a list
intersection_as_list = list(intersection_set)
print(intersection_as_list)  # Print the intersection as a list

# intersection function can be represented as (&) 
set1 = {1, 2, 3}
set2 = {1, 9, 0}
set3 = set1 & set2 # set1.intersection(set2)

# Intersection of two sets
print("Intersection of set1 & set2 is: set3 =", set3)

# union() function
set1 = {2, 4, 5, 6}
set2 = {4, 6, 7, 8}

# Union of two sets
print("set1 U set2 : ", set1.union(set2))

# union() function can be represented as vertical slash (|) which is pipe delimiter

set1 = {2, 4, 5, 6}
set2 = {4, 6, 7, 8}

set3 = set1 | set2 # set1.union(set2)

# Union of two sets
print("Union of set1 & set2 is: set3 =", set3) # Printing the union of set1 and set2

# Defining three sets
set1 = {2, 4, 5, 6}
set2 = {4, 6, 7, 8}
set3 = {7, 8, 9, 10}

# Printing the union of set1, set2, and set3
print("set1 U set2 U set3 :", set1.union(set2, set3))

set1 | set2 | set3 # '|' delimiter is used

set1 & set2 & set3 # '&' is used

# Defining two sets 
set3 = {7, 8, 9, 10}
set4 = {9, 10}

# Difference between set3 and set4
set5 = set3 - set4
print("Elements in set3 and not in set4: set5 = ", set5)

# check if set4 and set5 are disjoint sets
if set4.isdisjoint(set5): # 'if' is a conditional statement
    print("set4 and set5 have nothing in common\n")

# Removing all the values of set5
set5.clear()
print("After applying clear on set set5: ")
print("set5 = ", set5)

### Frozen set 
# - Frozen set is just an immutable version of a Python set object.
# - While elements of a 'set' can be modified at any time, elements of the 
#   'frozen set' remains the same after creation.
# - Syntax: frozenset([iterable])

# Define a tuple of vowels
vowels = ('a', 'e', 'i', 'o', 'u')

# Create a frozen set from the tuple
fSet = frozenset(vowels)

# Print the frozen set
print('The frozen set is:', fSet)

# frozensets are immutable
fSet.add('v')

# To get detailed info about set
help(set)

# Mappings / Dictionaries
# - Dictionaries are unordered Key-value-pairs.
# - Keys are Immutable, where as, the values are mutable. 
# - Dictionary is created by using curly brackets - {}.
# - Dictionaries are accessed via keys and not via their position. 
# - A dictionary is an associative array (also known as hashes). Any key of the 
#   dictionary is associated (or mapped) to a value(s). 
# - The values of a dictionary can be any Python data type.
# - Dictionaries don't support the sequence operation of the sequence data 
#   types like strings, tuples and lists.

# Dictionaries ---> dict ---> Unordered key value pairs {'mykey':'value'} 
# (Also known as Mapping Type).

# Accessing values in dictionary

# Define a dictionary
dict1 = {'Name': 'Aditya', 'Age': 40, 'bike': 'Honda'}

# Print the dictionary
print(dict1)

# Access value using key
print(dict1['Name'])
print(dict1['Age'])

# Updating dictionary
dict1 = {'Name': 'Divit', 'Age': 8, 'bike': 'Bicycle'}

# Update existing entry
dict1['Age'] = 9

# Add new entry
dict1['School'] = "DPS School"

dict1['Salary'] = 50000

# Print updated values
print(dict1['Age'])
print(dict1['School'])
print(dict1['Salary'])

# Retrieve keys, values, and items of the dictionary
print(dict1.keys())
print(dict1.values())
print(dict1.items())

# Delete dictionary elements
del(dict1['Name']) # Remove entry with key 'Name'
dict1

# Remove all entries in given dictionary
dict1.clear()
dict1

# Delete entire dictionary
del(dict1)

# Once dictionary is deleted the object does not exist
print(dict1['Age']) 

# Accessing elements in a dictionary
d = {'k1': 123, 'k2': [8, 9, 10], 'k3': {'inside_key': 100}}

# Print the dictionary
print(d)

# Access value using key
print(d['k1'])

# Access value of a list within the dictionary
print(d['k2'])

# Access specific element within the list
print(d['k2'][1])

# Access value of a nested dictionary within the dictionary
print(d['k3'])

# Access value of a key within the nested dictionary
print(d['k3']['inside_key'])


## Categorizing the Standard Types
# - Data Type --------> Storage Model ---> Update Model ---> Access Model
# - Numbers ----------> Scalar ----------> Immutable ------> Direct
# - Strings ----------> Scalar ----------> Immutable ------> Sequence
# - Lists ------------> Container -------> Mutable --------> Sequence
# - Tuples -----------> Container -------> Immutable ------> Sequence
# - Dictionaries -----> Container -------> Mutable --------> Mapping

# END OF MODULE
